<?php

/*
 * The time rules around the end of an election (docs/slices/13-opening-and-closing.md "Rules", docs/design/database.md 4,
 * FR-ELEC-05, NFR-OPS-03): the server time decides, not the status alone, and an extension is read by the vote
 * transaction. Endpoints: docs/api/vote/POST-vote-{election}-submit.md, docs/api/elections/POST-elections-{election}-extend.md.
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\Team;
use Tests\Support\Voting;

/** An election of A ending at `$ends`, a voter inside a session, an owner of A signed in on `$this->browser`. Returns [election data, voter browser]. */
function aroundEnd($test, string $ends): array
{
    $t = Team::two();
    $e = Voting::election(['institution' => $t['a']['owner']['institution'], 'ends_at' => $ends]);
    $voter = Voting::browser($test);
    Voting::enter($voter, $e['election'], $e['codes']['Wideline'])->assertCreated();
    Team::signIn($test, $t['a']['owner']);

    return [$e, $voter];
}

function submitAround($voter, array $e)
{
    return $voter->post("/api/v1/vote/{$e['election']}/submit", Voting::body($e), [], false);
}

it('refuses a vote after the end even though the status still says open, and records nothing [FR-ELEC-05, FR-VOTE-02] (rule "After the end")', function () {
    [$e, $voter] = aroundEnd($this, now('UTC')->addMinutes(3)->toDateTimeString());
    $this->travel(4)->minutes();

    expect(Accounts::electionRow($e['election'])['status'])->toBe('open');
    submitAround($voter, $e)->assertStatus(409)->assertJsonPath('error.code', 'election_not_open');

    expect(Voting::counts($e['election']))->toBe([0, 0, 0, 0]);
});

it('accepts a vote after the old end but before the new one once the end was extended [FR-ELEC-05] (extend, notes)', function () {
    [$e, $voter] = aroundEnd($this, now('UTC')->addMinutes(3)->toDateTimeString());
    $this->browser->post("/api/v1/elections/{$e['election']}/extend", ['ends_at' => now('UTC')->addHours(2)->format('Y-m-d\TH:i:s\Z')])->assertOk();
    $this->travel(4)->minutes();

    submitAround($voter, $e)->assertCreated();

    expect(Voting::counts($e['election'])[0])->toBe(1);
});

it('refuses a vote on a closed election with the same code as a late vote and records nothing [FR-ELEC-05] (close, scenario 5)', function () {
    [$e, $voter] = aroundEnd($this, now('UTC')->addDay()->toDateTimeString());
    DB::connection(useMigratorConnection())->table('elections')->where('uuid', $e['election'])->update(['status' => 'closed', 'closed_at' => now('UTC')->toDateTimeString()]);

    submitAround($voter, $e)->assertStatus(409)->assertJsonPath('error.code', 'election_not_open');

    expect(Voting::counts($e['election']))->toBe([0, 0, 0, 0]);
});

it('does not let the vote transaction read the old end after an extension was refused [FR-ELEC-05] (extend, scenario 3)', function () {
    [$e, $voter] = aroundEnd($this, now('UTC')->addMinutes(3)->toDateTimeString());
    $this->travel(4)->minutes();

    $this->browser->post("/api/v1/elections/{$e['election']}/extend", ['ends_at' => now('UTC')->addHours(2)->format('Y-m-d\TH:i:s\Z')])->assertStatus(409)->assertJsonPath('error.code', 'election_not_extendable');

    submitAround($voter, $e)->assertStatus(409)->assertJsonPath('error.code', 'election_not_open');
});
