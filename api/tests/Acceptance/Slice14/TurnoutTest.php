<?php

/*
 * GET /api/v1/elections/{election}/turnout — docs/api/turnout/GET-elections-{election}-turnout.md
 * Tenant suite, route api/v1/elections/{election}/turnout: another institution's election answers 404, the same as an
 * unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;
use Tests\Support\Turnout;
use Tests\Support\Voting;

const TURNOUT_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function turnoutUrl(string $uuid, string $query = ''): string
{
    return "/api/v1/elections/{$uuid}/turnout{$query}";
}

/** An owner of A signed in and a planted election of A. Returns [team, election]. */
function turnoutSignedIn($test, array $options = []): array
{
    $t = Team::two();
    $election = Accounts::plantElection($options + ['institution' => $t['a']['owner']['institution'], 'status' => 'open', 'timezone' => 'UTC']);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('gives eligible, voted and a one-decimal percentage, by group, with the line without group last [FR-RES-01] (scenarios 1, 3)', function () {
    [$t, $election] = turnoutSignedIn($this);
    $g4 = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $eg = Accounts::plantGroup(['election' => $election, 'name' => 'élèves libres']);
    $g3 = Accounts::plantGroup(['election' => $election, 'name' => '3e année']);
    $voters = [];
    foreach ([[$g3, 3], [$g4, 2], [$eg, 1], [null, 3]] as [$group, $count]) {
        foreach (range(1, $count) as $i) {
            $voters[] = ['uuid' => Accounts::plantVoter(['election' => $election, 'group' => $group]), 'group' => $group];
        }
    }
    foreach ([0, 1, 3, 6, 8] as $i) {
        Turnout::voted($voters[$i]['uuid'], '2026-10-12 12:30:00');
    }

    $data = $this->browser->get(turnoutUrl($election))->assertOk()->json('data');

    expect($data['eligible'])->toBe(9)
        ->and($data['voted'])->toBe(5)
        ->and($data['percentage'])->toBe(55.6)
        ->and($data['by_group'])->toBe([
            ['group' => ['id' => $g3, 'name' => '3e année'], 'voters' => 3, 'voted' => 2],
            ['group' => ['id' => $g4, 'name' => '4e année'], 'voters' => 2, 'voted' => 1],
            ['group' => ['id' => $eg, 'name' => 'élèves libres'], 'voters' => 1, 'voted' => 0],
            ['group' => null, 'voters' => 3, 'voted' => 2],
        ]);
});

it('gives only counts: no ballot, candidate, vote or voter name, no numeric id [FR-SEC-03, FR-SEC-04] (scenario 1)', function () {
    $t = Team::two();
    $e = Voting::election(['institution' => $t['a']['owner']['institution']]);
    Turnout::voted($e['voters']['Wideline'], now('UTC')->subMinutes(5)->toDateTimeString());
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->get(turnoutUrl($e['election']))->assertOk();

    expect(array_keys($response->json('data')))->toBe(['eligible', 'voted', 'percentage', 'by_group', 'bucket', 'over_time'])
        ->and($response->getContent())->not->toContain('Wideline')
        ->and($response->getContent())->not->toContain($e['ballots']['P'])
        ->and($response->getContent())->not->toContain($e['candidates']['A']);
    foreach ($response->json('data.over_time') as $bucket) {
        expect(array_keys($bucket))->toBe(['at', 'voted', 'total']);
    }
});

it('buckets by hour for a short election, empty hours listed, with a running total [FR-RES-01] (scenario 1)', function () {
    [$t, $election] = turnoutSignedIn($this, ['status' => 'closed', 'starts_at' => '2026-10-12 12:00:00', 'ends_at' => '2026-10-12 16:30:00']);
    $voters = array_map(fn ($i) => Accounts::plantVoter(['election' => $election]), range(1, 4));
    Turnout::voted($voters[0], '2026-10-12 12:10:00');
    Turnout::voted($voters[1], '2026-10-12 12:50:00');
    Turnout::voted($voters[2], '2026-10-12 14:05:00');

    $data = $this->browser->get(turnoutUrl($election))->assertOk()->json('data');

    expect($data['bucket'])->toBe('hour')
        ->and($data['over_time'])->toBe([
            ['at' => '2026-10-12T12:00:00Z', 'voted' => 2, 'total' => 2],
            ['at' => '2026-10-12T13:00:00Z', 'voted' => 0, 'total' => 2],
            ['at' => '2026-10-12T14:00:00Z', 'voted' => 1, 'total' => 3],
            ['at' => '2026-10-12T15:00:00Z', 'voted' => 0, 'total' => 3],
            ['at' => '2026-10-12T16:00:00Z', 'voted' => 0, 'total' => 3],
        ])
        ->and($data['percentage'])->toBe(75.0);
});

it('runs an open election\'s series up to the current hour [FR-RES-01] (scenario 1)', function () {
    $start = now('UTC')->subHours(2)->startOfHour();
    [$t, $election] = turnoutSignedIn($this, ['starts_at' => $start->toDateTimeString(), 'ends_at' => now('UTC')->addDay()->toDateTimeString()]);
    $voter = Accounts::plantVoter(['election' => $election]);
    Turnout::voted($voter, $start->copy()->addMinutes(20)->toDateTimeString());

    $series = $this->browser->get(turnoutUrl($election))->assertOk()->json('data.over_time');

    expect($series)->toHaveCount(3)
        ->and(array_column($series, 'voted'))->toBe([1, 0, 0])
        ->and(array_column($series, 'total'))->toBe([1, 1, 1]);
});

it('buckets by day in the election\'s time zone for an election longer than three days, and honours bucket=hour [FR-RES-01] (scenario 1)', function () {
    [$t, $election] = turnoutSignedIn($this, ['status' => 'closed', 'timezone' => 'Asia/Kolkata', 'starts_at' => '2026-10-12 20:00:00', 'ends_at' => '2026-10-17 10:00:00']);
    $a = Accounts::plantVoter(['election' => $election]);
    $b = Accounts::plantVoter(['election' => $election]);
    Turnout::voted($a, '2026-10-13 00:00:00');
    Turnout::voted($b, '2026-10-14 19:00:00');

    $day = $this->browser->get(turnoutUrl($election))->assertOk()->json('data');
    $hour = $this->browser->get(turnoutUrl($election, '?bucket=hour'))->assertOk()->json('data');

    expect($day['bucket'])->toBe('day')
        ->and($day['over_time'])->toBe([
            ['at' => '2026-10-12T18:30:00Z', 'voted' => 1, 'total' => 1],
            ['at' => '2026-10-13T18:30:00Z', 'voted' => 0, 'total' => 1],
            ['at' => '2026-10-14T18:30:00Z', 'voted' => 1, 'total' => 2],
            ['at' => '2026-10-15T18:30:00Z', 'voted' => 0, 'total' => 2],
            ['at' => '2026-10-16T18:30:00Z', 'voted' => 0, 'total' => 2],
        ])
        ->and($hour['bucket'])->toBe('hour')
        ->and(count($hour['over_time']))->toBeGreaterThan(100)
        ->and(end($hour['over_time'])['total'])->toBe(2);
});

it('answers zeros and an empty series for an election with no voter or no vote [FR-RES-01] (scenario 2)', function () {
    [$t, $election] = turnoutSignedIn($this);
    $none = $this->browser->get(turnoutUrl($election))->assertOk()->json('data');
    Accounts::plantVoter(['election' => $election]);
    $noVote = $this->browser->get(turnoutUrl($election))->assertOk()->json('data');

    expect($none)->toMatchArray(['eligible' => 0, 'voted' => 0, 'percentage' => 0.0, 'by_group' => [], 'over_time' => []])
        ->and($noVote)->toMatchArray(['eligible' => 1, 'voted' => 0, 'percentage' => 0.0, 'over_time' => []]);
});

it('answers zeros for a draft or scheduled election, and the same numbers for a closed, published or archived one [FR-RES-01] (scenarios 4, 5)', function (string $status, int $voted) {
    [$t, $election] = turnoutSignedIn($this, ['status' => $status, 'starts_at' => '2026-10-12 12:00:00', 'ends_at' => '2026-10-12 18:00:00']);
    $voter = Accounts::plantVoter(['election' => $election]);
    Accounts::plantVoter(['election' => $election]);
    if ($voted > 0) {
        Turnout::voted($voter, '2026-10-12 13:00:00');
    }

    $data = $this->browser->get(turnoutUrl($election))->assertOk()->json('data');

    expect($data['eligible'])->toBe(2)->and($data['voted'])->toBe($voted)->and($data['percentage'])->toBe($voted === 1 ? 50.0 : 0.0);
})->with([
    'draft' => ['draft', 0],
    'scheduled' => ['scheduled', 0],
    'closed' => ['closed', 1],
    'published' => ['published', 1],
    'archived' => ['archived', 1],
]);

it('answers 422 for a bucket that is not hour or day [FR-RES-01] (scenario 6)', function () {
    [$t, $election] = turnoutSignedIn($this);

    $this->browser->get(turnoutUrl($election, '?bucket=week'))->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.fields.bucket.0', 'invalid');
});

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 7)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution'], 'status' => 'open']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->get(turnoutUrl(TURNOUT_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(turnoutUrl($target))))->toBe($unknown);
    }
});

it('answers 401, 403 and 405 as every read [NFR-SEC-04] (scenario 8)', function () {
    [$t, $election] = turnoutSignedIn($this);
    $this->browser->forgetCookies();

    $this->browser->get(turnoutUrl($election))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->post(turnoutUrl($election), [])->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->get(turnoutUrl($election))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('lets a manager read it too [FR-RES-01] (scenario 1)', function () {
    [$t, $election] = turnoutSignedIn($this);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->get(turnoutUrl($election))->assertOk();
});
