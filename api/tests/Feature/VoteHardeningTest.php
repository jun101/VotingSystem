<?php

/*
 * The voting routes after the review of slice 12: atomic wrong-code counting, IPv6 prefixes and the
 * lower-cased election in the public limiter, the code length, the JSON guard, a vote seen from
 * another session, a code run that skips a voter who voted, and the retried voter update.
 */

use App\Actions\Credentials\GenerateSlipFile;
use App\Support\Credentials\SlipSheet;
use App\Support\Credentials\SlipWriter;
use App\Support\Voting\CodeAttempts;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Codes;
use Tests\Support\Team;
use Tests\Support\Voting;

const HARDENING_ELECTION = '6f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

beforeEach(function () {
    Cache::flush();
    $this->browser = new AuthClient($this);
});

it('counts a wrong code by its increment, lets the 30th through and refuses the 31st without counting it [NFR-SEC-05]', function () {
    $attempts = new CodeAttempts;

    for ($i = 1; $i <= 30; $i++) {
        expect($attempts->begin(HARDENING_ELECTION, '10.0.0.1'))->toBeNull();
    }

    $key = 'vote-code:address:'.HARDENING_ELECTION.':'.hash('sha256', '10.0.0.1');
    expect($attempts->begin(HARDENING_ELECTION, '10.0.0.1'))->toBeGreaterThan(0)->toBeLessThanOrEqual(600)
        // The refused request is given back: the address stays at its maximum, never above.
        ->and(RateLimiter::attempts($key))->toBe(30)
        ->and(RateLimiter::attempts('vote-code:election:'.HARDENING_ELECTION))->toBe(30)
        ->and($attempts->begin(HARDENING_ELECTION, '10.0.0.2'))->toBeNull();
});

it('gives a valid code back, so valid codes are never counted [FR-VOTE-08]', function () {
    $attempts = new CodeAttempts;

    foreach (range(1, 100) as $i) {
        expect($attempts->begin(HARDENING_ELECTION, '10.0.0.1'))->toBeNull();
        $attempts->forgive(HARDENING_ELECTION, '10.0.0.1');
    }

    expect(RateLimiter::attempts('vote-code:address:'.HARDENING_ELECTION.':'.hash('sha256', '10.0.0.1')))->toBe(0)
        ->and(RateLimiter::attempts('vote-code:election:'.HARDENING_ELECTION))->toBe(0);
});

it('counts the addresses of one IPv6 /64 together and another prefix apart [NFR-SEC-05]', function () {
    $attempts = new CodeAttempts;

    for ($i = 1; $i <= 30; $i++) {
        expect($attempts->begin(HARDENING_ELECTION, '2001:db8:1:2::'.dechex($i)))->toBeNull();
    }

    expect($attempts->begin(HARDENING_ELECTION, '2001:db8:1:2:ffff::1'))->not->toBeNull()
        ->and($attempts->begin(HARDENING_ELECTION, '2001:db8:1:3::1'))->toBeNull();
});

it('holds a voting route to the public limiter by /64 and by the election in lower case [NFR-SEC-05]', function () {
    Accounts::reset();
    $e = Voting::election();
    $url = fn (string $election) => "/api/v1/vote/{$election}";
    $remaining = fn ($response) => (int) $response->headers->get('X-RateLimit-Remaining');

    $first = Voting::browser($this)->fromAddress('2001:db8:aa:bb::1')->get($url($e['election']))->assertOk();
    $sameBlock = Voting::browser($this)->fromAddress('2001:db8:aa:bb:1:2:3:4')->get($url(strtoupper($e['election'])))->assertOk();
    $otherBlock = Voting::browser($this)->fromAddress('2001:db8:aa:cc::1')->get($url($e['election']))->assertOk();

    expect($remaining($sameBlock))->toBe($remaining($first) - 1)
        ->and($remaining($otherBlock))->toBe($remaining($first));
});

it('holds the session and submit routes to the public limiter too, without counters on the session [NFR-SEC-05]', function () {
    Accounts::reset();
    config(['votes.public_per_minute' => 2]);
    $e = Voting::election();
    $browser = Voting::browser($this)->fromAddress('10.7.7.7');

    $first = Voting::enter($browser, $e['election'], $e['codes']['Wideline'])->assertCreated();
    $second = $browser->post("/api/v1/vote/{$e['election']}/submit", ['ballots' => []], [], false);
    $third = Voting::enter($browser, $e['election'], $e['codes']['Wideline']);

    expect($first->headers->has('X-RateLimit-Remaining'))->toBeFalse()
        ->and($second->getStatusCode())->not->toBe(429)
        ->and($third->getStatusCode())->toBe(429)
        ->and($third->headers->has('Retry-After'))->toBeTrue();
});

it('answers 422 for a code longer than 64 characters and counts nothing [FR-VOTE-01]', function () {
    Accounts::reset();
    $e = Voting::election();
    $browser = Voting::browser($this)->fromAddress('10.8.8.8');

    $long = Voting::enter($browser, $e['election'], str_repeat('A', 65))->assertStatus(422);
    $edge = Voting::enter($browser, $e['election'], str_repeat('A', 64))->assertStatus(401);

    expect($long->json('error.code'))->toBe('validation_failed')
        ->and($long->json('error.fields.code'))->toContain('invalid')
        ->and($edge->json('error.code'))->toBe('invalid_code')
        ->and(RateLimiter::attempts('vote-code:address:'.$e['election'].':'.hash('sha256', '10.8.8.8')))->toBe(1);
});

it('accepts only the application/json media type, whatever its case and parameters [NFR-SEC-04]', function (string $type, int $status) {
    Accounts::reset();
    $e = Voting::election();

    $response = $this->call('POST', "/api/v1/vote/{$e['election']}/session", [], [], [], [
        'CONTENT_TYPE' => $type,
        'HTTP_ACCEPT' => 'application/json',
    ], json_encode(['code' => Codes::fresh()]));

    expect($response->getStatusCode())->toBe($status);
})->with([
    'plain' => ['application/json', 401],
    'upper case and charset' => ['Application/JSON ; charset=UTF-8', 401],
    'a +json type' => ['application/vnd.api+json', 400],
    'a form' => ['application/x-www-form-urlencoded', 400],
    'a prefix of json' => ['application/jsonp', 400],
    'text' => ['text/plain', 400],
]);

it('answers 409 already_voted when a voter who voted in an earlier session enters the code again [FR-SEC-02, FR-VOTE-06]', function () {
    Accounts::reset();
    Voting::keypair();
    $e = Voting::election();
    $first = Voting::browser($this);
    $second = Voting::browser($this);
    Voting::enter($first, $e['election'], $e['codes']['Wideline'])->assertCreated();

    $first->post("/api/v1/vote/{$e['election']}/submit", Voting::body($e, ['G' => ['H']]), [], false)->assertCreated();

    // One live session per voter (slice 12b): a new session is refused once the voter has voted.
    Voting::enter($second, $e['election'], $e['codes']['Wideline'])->assertStatus(409)->assertJsonPath('error.code', 'already_voted');
});

it('gives no new code to a voter whose email failed and who voted, in the run and in its preview [FR-CRED-04]', function () {
    Accounts::reset();
    Codes::useTempDisk();
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $voted = Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose Désir', 'email' => 'rose@example.ht']);
    $waiting = Accounts::plantVoter(['election' => $election, 'full_name' => 'Jean Pierre', 'email' => 'jean@example.ht']);
    $oldVoted = Accounts::plantCredential(['voter' => $voted, 'email_status' => 'failed']);
    Accounts::plantCredential(['voter' => $waiting, 'email_status' => 'failed']);
    $db = DB::connection(useMigratorConnection());
    $row = $db->table('voters')->where('uuid', $voted)->first();
    $db->table('participations')->insert(['election_id' => $row->election_id, 'voter_id' => $row->id, 'voted_at' => now('UTC')->toDateTimeString()]);

    Team::signIn($this, $t['a']['owner']);
    $preview = $this->browser->post("/api/v1/elections/{$election}/credential-previews", ['scope' => 'failed_email'])->assertOk();
    expect($preview->json('data.to_generate'))->toBe(1)->and($preview->json('data.with_email'))->toBe(1);

    // The queued file was planned for both (the voter voted since): the file run leaves the voter alone.
    $file = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'voter_count' => 2]);
    $db->table('generated_files')->where('uuid', $file)->update(['send_email' => 1, 'replace' => 1]);
    app()->instance(SlipWriter::class, new class implements SlipWriter
    {
        public function write(SlipSheet $sheet): string
        {
            return '%PDF-1.4 '.count($sheet->slips).' slips';
        }
    });

    app(GenerateSlipFile::class)($file);

    expect(Accounts::credentialRow($voted)['reissue_count'])->toBe(0)
        ->and(Accounts::credentialRow($voted)['code_hash'])->toBe(Codes::hash($oldVoted))
        ->and(Accounts::credentialRow($waiting)['reissue_count'])->toBe(1)
        ->and(Accounts::mailTo('rose@example.ht'))->toBe([])
        ->and(Accounts::mailTo('jean@example.ht'))->toHaveCount(1);
});

it('retries a voter update that the database broke with a deadlock [FR-VOT-06]', function () {
    Accounts::reset();
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => 'draft']);
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Avant']);
    Team::signIn($this, $t['a']['owner']);

    $thrown = 0;
    DB::listen(function ($query) use (&$thrown): void {
        // The voter's row is locked inside the update's transaction: break the first one like a deadlock.
        if ($thrown === 0 && DB::transactionLevel() > 0 && str_contains($query->sql, 'from `voters`') && str_contains($query->sql, 'for update')) {
            $thrown++;

            throw new QueryException($query->connectionName, $query->sql, $query->bindings, new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        }
    });

    $this->browser->patch("/api/v1/voters/{$voter}", ['full_name' => 'Après'])->assertOk()->assertJsonPath('data.full_name', 'Après');

    expect($thrown)->toBe(1)->and(Accounts::voterRow($voter)['full_name'])->toBe('Après');
});
