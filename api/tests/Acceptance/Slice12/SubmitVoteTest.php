<?php

/*
 * POST /api/v1/vote/{election}/submit — docs/api/vote/POST-vote-{election}-submit.md and docs/design/database.md 4.
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\Voting;

function submitUrl(string $election): string
{
    return "/api/v1/vote/{$election}/submit";
}

/** A voter inside a session with the audit key set. Returns [election data, browser]. */
function submitEnter($test, string $who = 'Wideline', array $options = []): array
{
    $e = Voting::election($options);
    $browser = Voting::browser($test);
    Voting::enter($browser, $e['election'], $e['codes'][$who])->assertCreated();

    return [$e, $browser];
}

function submitPost($browser, array $e, array $body)
{
    return $browser->post(submitUrl($e['election']), $body, [], false);
}

it('records one participation, one vote per ballot, its choices and one audit record per vote [FR-VOTE-04, FR-SEC-01, FR-SEC-08] (scenario 1)', function () {
    [$e, $browser] = submitEnter($this);

    $response = submitPost($browser, $e, Voting::body($e, ['P' => ['B'], 'S' => ['D', 'F'], 'G' => ['I']]))->assertCreated();

    expect(Voting::counts($e['election']))->toBe([1, 3, 4, 3])
        ->and($response->json('data'))->toHaveKey('voted_at');
    $audit = Voting::audit($e['election'], $this->auditPair);
    expect($audit)->toHaveCount(3);
    foreach ($audit as $row) {
        expect($row['version'])->toBe(1)
            ->and($row['election'])->toBe($e['election'])
            ->and($row['voter'])->toBe($e['voters']['Wideline'])
            ->and($row['size'])->toBe(137)
            ->and($row['record'])->toBe($row['row_uuid'])
            ->and(abs($row['at'] - now()->timestamp))->toBeLessThan(10)
            ->and(in_array($row['ballot'], array_values($e['ballots']), true))->toBeTrue();
    }
    $db = DB::connection(useMigratorConnection());
    $participation = $db->table('participations')->first();
    expect($participation->voted_at)->not->toBeNull();
});

it('records a blank vote with no choice row on a ballot that allows it [FR-VOTE-04] (scenario 2)', function () {
    [$e, $browser] = submitEnter($this, 'Paul');

    submitPost($browser, $e, Voting::body($e, ['P' => [], 'S' => ['E']]))->assertCreated();

    expect(Voting::counts($e['election']))->toBe([1, 2, 1, 2]);
});

it('answers the same confirmation again for a re-sent submission and writes nothing more [FR-VOTE-05] (scenario 3)', function () {
    [$e, $browser] = submitEnter($this);
    $body = Voting::body($e, ['G' => ['H']]);
    $first = submitPost($browser, $e, $body)->assertCreated();

    $second = submitPost($browser, $e, $body)->assertOk();

    expect($second->json('data.voted_at'))->toBe($first->json('data.voted_at'))
        ->and(Voting::counts($e['election']))->toBe([1, 3, 4, 3]);
});

it('refuses the same code in a new session [FR-VOTE-05] (scenario 4)', function () {
    [$e, $browser] = submitEnter($this);
    submitPost($browser, $e, Voting::body($e, ['G' => ['H']]))->assertCreated();

    Voting::enter(Voting::browser($this), $e['election'], $e['codes']['Wideline'])->assertStatus(409)->assertJsonPath('error.code', 'already_voted');
    expect(Voting::counts($e['election'])[0])->toBe(1);
});

it('rejects the whole submission when a ballot is missing or one that is not eligible is present, recording nothing [FR-SEC-07] (scenario 5)', function (string $case) {
    [$e, $browser] = submitEnter($this, 'Paul');
    $body = match ($case) {
        'missing' => Voting::body($e, ['S' => null]),
        'extra' => Voting::body($e, ['G' => ['H']]),
        'duplicate ballot' => ['ballots' => array_merge(Voting::body($e)['ballots'], Voting::body($e)['ballots'])],
    };

    $response = submitPost($browser, $e, $body)->assertStatus(422);

    expect($response->json('error.code'))->toBe('ballots_mismatch')
        ->and(Voting::counts($e['election']))->toBe([0, 0, 0, 0]);
})->with(['missing', 'extra', 'duplicate ballot']);

it('rejects choices that break the rules of the ballot, recording nothing [FR-VOTE-04] (scenario 6)', function (string $case) {
    [$e, $browser] = submitEnter($this);
    $body = match ($case) {
        'too many' => Voting::body($e, ['P' => ['A', 'B'], 'G' => ['H']]),
        'blank not allowed' => Voting::body($e, ['S' => [], 'G' => ['H']]),
        'repeated' => Voting::body($e, ['S' => ['D', 'D'], 'G' => ['H']]),
        'other ballot' => Voting::body($e, ['P' => ['D'], 'G' => ['H']]),
        'unknown' => ['ballots' => [
            ['ballot' => $e['ballots']['P'], 'candidates' => ['3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa']],
            ['ballot' => $e['ballots']['S'], 'candidates' => [$e['candidates']['D']]],
            ['ballot' => $e['ballots']['G'], 'candidates' => []],
        ]],
    };

    $response = submitPost($browser, $e, $body)->assertStatus(422);

    expect($response->json('error.code'))->toBe('choices_invalid')
        ->and(Voting::counts($e['election']))->toBe([0, 0, 0, 0]);
})->with(['too many', 'blank not allowed', 'repeated', 'other ballot', 'unknown']);

it('answers 422 for a body that is not valid [FR-VOTE-04] (scenario 7)', function (array $body) {
    [$e, $browser] = submitEnter($this);

    $response = submitPost($browser, $e, $body)->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed');
})->with([
    'no ballots' => [[]],
    'not a list' => [['ballots' => 'oui']],
    'no candidates key' => [['ballots' => [['ballot' => '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa']]]],
    'ballot not a uuid' => [['ballots' => [['ballot' => '12', 'candidates' => []]]]],
    'candidate not a uuid' => [['ballots' => [['ballot' => '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa', 'candidates' => ['x']]]]],
]);

it('answers 409 election_not_open when the election is closed or past its end although the status says open [FR-ELEC-05] (scenario 8)', function (string $case) {
    [$e, $browser] = submitEnter($this);
    if ($case === 'closed') {
        Accounts::setElectionStatus($e['election'], 'closed');
    } else {
        DB::connection(useMigratorConnection())->table('elections')->where('uuid', $e['election'])->update(['ends_at' => now('UTC')->subMinute()->toDateTimeString()]);
    }

    submitPost($browser, $e, Voting::body($e, ['G' => ['H']]))->assertStatus(409)->assertJsonPath('error.code', 'election_not_open');
    expect(Voting::counts($e['election']))->toBe([0, 0, 0, 0]);
})->with(['closed', 'past its end']);

it('answers 401 without a session, with an expired one or an ended one [FR-VOTE-06] (scenario 9)', function () {
    [$e, $browser] = submitEnter($this);
    $body = Voting::body($e, ['G' => ['H']]);

    submitPost(Voting::browser($this), $e, $body)->assertStatus(401)->assertJsonPath('error.code', 'voter_session_expired');
    $this->travel(16)->minutes();
    submitPost($browser, $e, $body)->assertStatus(401)->assertJsonPath('error.code', 'voter_session_expired');
    expect(Voting::counts($e['election']))->toBe([0, 0, 0, 0]);
});

it('records exactly one vote when the same voter submits from several sessions [FR-SEC-02] (scenario 10)', function () {
    $e = Voting::election();
    $browsers = [];
    foreach (range(1, 5) as $i) {
        $browsers[$i] = Voting::browser($this);
        Voting::enter($browsers[$i], $e['election'], $e['codes']['Wideline'])->assertCreated();
    }

    $statuses = [];
    foreach ($browsers as $browser) {
        $statuses[] = submitPost($browser, $e, Voting::body($e, ['G' => ['H']]))->getStatusCode();
    }

    expect(array_count_values($statuses)[201] ?? 0)->toBe(1)
        ->and(Voting::counts($e['election']))->toBe([1, 3, 4, 3]);
    // Since slice 12b only the newest session of a voter is live: the earlier ones answer 401.
    foreach (array_filter($statuses, fn ($s) => $s !== 201) as $status) {
        expect($status)->toBe(401);
    }
});

it('records nothing at all when the audit key is missing or malformed [FR-SEC-02] (scenario 11)', function (string $key) {
    [$e, $browser] = submitEnter($this);
    config(['votes.audit_public_key' => $key]);

    $response = submitPost($browser, $e, Voting::body($e, ['G' => ['H']]));

    expect($response->getStatusCode())->toBe(500)
        ->and(Voting::counts($e['election']))->toBe([0, 0, 0, 0]);
})->with(['empty' => [''], 'short' => ['abcd'], 'not hex' => [str_repeat('zz', 32)]]);

it('answers 429 above 10 submissions a minute for a session [NFR-SEC-05] (scenario 12)', function () {
    [$e, $browser] = submitEnter($this);

    foreach (range(1, 10) as $i) {
        submitPost($browser, $e, ['ballots' => []])->assertStatus(422);
    }

    submitPost($browser, $e, ['ballots' => []])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});

it('answers 405 for another method [NFR-SEC-04] (scenario 13)', function () {
    [$e, $browser] = submitEnter($this);

    $browser->other('GET', submitUrl($e['election']))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
});

it('does not return the choices and does not log them [FR-VOTE-07, FR-SEC-01] (scenario 1)', function () {
    $before = [];
    foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
        $before[$file] = filesize($file);
    }
    [$e, $browser] = submitEnter($this);

    $response = submitPost($browser, $e, Voting::body($e, ['P' => ['C'], 'G' => ['I']]))->assertCreated();

    $logged = '';
    foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
        $logged .= (string) substr((string) file_get_contents($file), $before[$file] ?? 0);
    }
    expect(array_keys($response->json('data')))->toBe(['voted_at']);
    foreach ([$e['candidates']['C'], $e['candidates']['I'], $e['voters']['Wideline'], $e['codes']['Wideline']] as $secret) {
        expect($logged)->not->toContain($secret);
    }
});
