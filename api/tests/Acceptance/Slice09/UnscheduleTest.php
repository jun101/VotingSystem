<?php

/*
 * POST /api/v1/elections/{election}/unschedule — docs/api/elections/POST-elections-{election}-unschedule.md
 * Tenant suite, route api/v1/elections/{election}/unschedule: another institution's election answers 404, the same as
 * an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const UNSCHEDULE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function unscheduleUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/unschedule";
}

function unscheduleSignedIn($test, string $status = 'scheduled'): array
{
    $t = Team::two();
    // A scheduled election has not started yet: unscheduling is refused after the start time (slice 13).
    $election = Accounts::plantElection([
        'institution' => $t['a']['owner']['institution'],
        'status' => $status,
        'starts_at' => now('UTC')->addDay(),
        'ends_at' => now('UTC')->addDays(4),
    ]);
    $group = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $ballot = Accounts::plantBallot(['election' => $election, 'title' => 'Délégué', 'groups' => [$group]]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $ballot, $group];
}

it('takes a scheduled election back to draft and keeps ballots and their groups [FR-ELEC-03] (scenario 1)', function () {
    [$t, $election, $ballot, $group] = unscheduleSignedIn($this);

    $response = $this->browser->post(unscheduleUrl($election))->assertOk();

    expect($response->json('data'))->toMatchArray(['id' => $election, 'status' => 'draft'])
        ->and(Accounts::electionRow($election)['status'])->toBe('draft')
        ->and(Accounts::ballotGroups($ballot))->toBe([$group]);
    $this->browser->post("/api/v1/elections/{$election}/ballots", ['title' => 'Nouveau'])->assertCreated();
});

it('answers 409 election_not_scheduled for any other status [FR-ELEC-03] (scenario 2)', function (string $status) {
    [$t, $election] = unscheduleSignedIn($this, $status);

    $this->browser->post(unscheduleUrl($election))->assertStatus(409)->assertJsonPath('error.code', 'election_not_scheduled');

    expect(Accounts::electionRow($election)['status'])->toBe($status);
})->with(['draft', 'open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 3)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution'], 'status' => 'scheduled']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->post(unscheduleUrl(UNSCHEDULE_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(unscheduleUrl($target))))->toBe($unknown);
    }
    expect(Accounts::electionRow($foreign)['status'])->toBe('scheduled');
});

it('answers 401, 403, 419 and 405 as every write [NFR-SEC-04] (scenarios 4 to 6 and 8)', function () {
    [$t, $election] = unscheduleSignedIn($this);
    $this->browser->forgetCookies();

    $this->browser->post(unscheduleUrl($election))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->post(unscheduleUrl($election), [], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('GET', unscheduleUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->post(unscheduleUrl($election))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::electionRow($election)['status'])->toBe('scheduled');
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 7)', function () {
    [$t, $election] = unscheduleSignedIn($this, 'draft');
    foreach (range(1, 120) as $i) {
        $this->browser->post(unscheduleUrl($election))->assertStatus(409);
    }

    $this->browser->post(unscheduleUrl($election))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
