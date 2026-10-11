<?php

/*
 * One vote per voter when requests really arrive together (FR-SEC-02, docs/design/database.md section 4).
 *
 * A test in one process cannot make two requests overlap, so these run the real RecordVote in
 * separate PHP processes against the same database: each opens its own voter session (the test
 * cache is in memory and cannot be shared) and they all start at the same moment.
 *
 * Why they fail without the protections:
 *  - without `lockForUpdate` on the voter AND without the unique key `participations (election_id, voter_id)`,
 *    every process passes the "has not voted" check at once and writes its own participation, votes and audit
 *    rows: the counts below are N times too big and the test fails;
 *  - without the lock alone, the unique key still stops the double (a 1062 turned into 409) and the counts hold,
 *    which is why the lock has its own test: a process must WAIT while another holds the voter's row;
 *  - the key alone is shown to refuse a second row at the database, whatever the application does.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\Accounts;
use Tests\Support\Voting;

beforeEach(function () {
    Accounts::reset();
    $this->pair = Voting::keypair();
});

/** The script one child runs: open a session for the voter, wait for the start, record the vote. */
function concurrencyScript(): string
{
    $path = tempnam(sys_get_temp_dir(), 'vote-child-');
    file_put_contents($path, <<<'PHP'
<?php
[$self, $startAt, $electionId, $voterId, $reissues, $issuedAt, $body] = $argv;
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$election = App\Models\Election::withoutInstitutionScope()->whereKey((int) $electionId)->firstOrFail();
$session = $app->make(App\Support\Voting\VoterSessions::class)->open((int) $voterId, (int) $electionId, (int) $reissues, (int) $issuedAt);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $at = $app->make(App\Actions\Voting\RecordVote::class)($election, $session, json_decode($body, true));
    echo json_encode(['status' => 201, 'at' => $at->getTimestamp()]);
} catch (App\Exceptions\ApiException $e) {
    echo json_encode(['status' => $e->getStatusCode(), 'code' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['status' => 500, 'code' => get_class($e).': '.$e->getMessage()]);
}
PHP);

    return $path;
}

/**
 * @param  array<string, mixed>  $e
 * @return array{election: int, voter: int, reissues: int, issued: int, body: string} what a child needs to vote for the voter
 */
function concurrencyVoter(array $e, string $who = 'Wideline'): array
{
    $db = DB::connection(useMigratorConnection());
    $voter = $db->table('voters')->where('uuid', $e['voters'][$who])->first();
    $credential = $db->table('credentials')->where('voter_id', $voter->id)->first();

    return [
        'election' => (int) $voter->election_id,
        'voter' => (int) $voter->id,
        'reissues' => (int) $credential->reissue_count,
        'issued' => (int) Carbon::parse($credential->issued_at, 'UTC')->getTimestamp(),
        'body' => json_encode(Voting::body($e, ['G' => ['H']])['ballots']),
    ];
}

function concurrencyChild(string $script, float $startAt, array $v, string $key): Process
{
    $process = new Process(
        [PHP_BINARY, $script, (string) $startAt, (string) $v['election'], (string) $v['voter'], (string) $v['reissues'], (string) $v['issued'], $v['body']],
        base_path(),
        ['VOTE_AUDIT_PUBLIC_KEY' => $key],
    );
    $process->setTimeout(120);
    $process->start();

    return $process;
}

function concurrencyResult(Process $process): array
{
    $process->wait();
    $out = json_decode(trim($process->getOutput()), true);

    if (! is_array($out)) {
        throw new RuntimeException('A child answered nothing: '.$process->getErrorOutput().$process->getOutput());
    }

    return $out;
}

it('records exactly one vote when many sessions of one voter submit at the same moment [FR-SEC-02]', function () {
    $e = Voting::election();
    $v = concurrencyVoter($e);
    $script = concurrencyScript();
    $key = bin2hex(sodium_crypto_box_publickey($this->pair));
    $start = microtime(true) + 6;

    try {
        $children = [];
        foreach (range(1, 8) as $i) {
            $children[] = concurrencyChild($script, $start, $v, $key);
        }
        $results = array_map(concurrencyResult(...), $children);
    } finally {
        @unlink($script);
    }

    $statuses = array_column($results, 'status');
    expect(array_count_values($statuses)[201] ?? 0)->toBe(1, 'one request records the vote: '.json_encode($results));
    foreach ($results as $result) {
        if ($result['status'] !== 201) {
            expect($result)->toBe(['status' => 409, 'code' => 'already_voted']);
        }
    }

    // One participation, three papers (P, S, G), their four choices (1 + 2 + 1), three audit records.
    expect(Voting::counts($e['election']))->toBe([1, 3, 4, 3]);

    $db = DB::connection(useMigratorConnection());
    $electionId = $db->table('elections')->where('uuid', $e['election'])->value('id');
    $papers = $db->table('votes')->where('election_id', $electionId)->pluck('ballot_id');
    expect($papers->unique()->count())->toBe(3);
});

it('makes a vote ask for an exclusive lock on the voter row and wait for it [FR-SEC-02]', function () {
    $e = Voting::election();
    $v = concurrencyVoter($e);
    $script = concurrencyScript();
    $key = bin2hex(sodium_crypto_box_publickey($this->pair));
    $db = DB::connection(useMigratorConnection());

    $db->beginTransaction();

    try {
        // A SHARED lock on the row: the foreign key check of the participation insert only needs a shared one
        // and would pass, so only the vote's own `FOR UPDATE` on the voter makes it wait.
        $db->table('voters')->where('id', $v['voter'])->sharedLock()->first();
        $child = concurrencyChild($script, microtime(true) + 2, $v, $key);

        // Well after the child has booted and reached the vote: it is still waiting for the row.
        sleep(6);
        expect($child->isRunning())->toBeTrue('the vote did not wait for the voter row')
            ->and(Voting::counts($e['election']))->toBe([0, 0, 0, 0]);
    } finally {
        $db->commit();
    }

    $result = concurrencyResult($child);
    @unlink($script);

    expect($result['status'])->toBe(201)
        ->and(Voting::counts($e['election']))->toBe([1, 3, 4, 3]);
});

it('refuses a second participation of a voter at the database itself [FR-SEC-02]', function () {
    $e = Voting::election();
    $db = DB::connection(useMigratorConnection());
    $voter = $db->table('voters')->where('uuid', $e['voters']['Paul'])->first();
    $row = ['election_id' => $voter->election_id, 'voter_id' => $voter->id, 'voted_at' => now('UTC')->toDateTimeString()];

    $db->table('participations')->insert($row);

    expect(fn () => $db->table('participations')->insert($row))->toThrow(QueryException::class);
});
