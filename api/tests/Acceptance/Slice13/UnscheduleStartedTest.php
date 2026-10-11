<?php

/*
 * POST /api/v1/elections/{election}/unschedule, slice 13 scenario 30 — docs/api/elections/POST-elections-{election}-unschedule.md
 * "Slice 13: a started election". The slice 09 scenarios stay in Slice09/UnscheduleTest.php.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

it('answers 409 election_started when the election is scheduled but its start has passed [FR-ELEC-03] (scenario 30)', function () {
    $t = Team::two();
    $election = Accounts::plantElection([
        'institution' => $t['a']['owner']['institution'],
        'status' => 'scheduled',
        'starts_at' => now('UTC')->subMinute()->toDateTimeString(),
        'ends_at' => now('UTC')->addDay()->toDateTimeString(),
    ]);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->post("/api/v1/elections/{$election}/unschedule")->assertStatus(409)->assertJsonPath('error.code', 'election_started');

    expect(Accounts::electionRow($election)['status'])->toBe('scheduled');
});

it('still unschedules an election whose start is ahead [FR-ELEC-03] (scenario 1)', function () {
    $t = Team::two();
    $election = Accounts::plantElection([
        'institution' => $t['a']['owner']['institution'],
        'status' => 'scheduled',
        'starts_at' => now('UTC')->addHour()->toDateTimeString(),
        'ends_at' => now('UTC')->addDay()->toDateTimeString(),
    ]);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->post("/api/v1/elections/{$election}/unschedule")->assertOk()->assertJsonPath('data.status', 'draft');
});

it('answers election_not_scheduled before election_started for an election that is not scheduled [FR-ELEC-03] (scenario 2)', function () {
    $t = Team::two();
    $election = Accounts::plantElection([
        'institution' => $t['a']['owner']['institution'],
        'status' => 'open',
        'starts_at' => now('UTC')->subHour()->toDateTimeString(),
        'ends_at' => now('UTC')->addDay()->toDateTimeString(),
    ]);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->post("/api/v1/elections/{$election}/unschedule")->assertStatus(409)->assertJsonPath('error.code', 'election_not_scheduled');
});
