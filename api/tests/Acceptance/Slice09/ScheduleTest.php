<?php

/*
 * POST /api/v1/elections/{election}/schedule — docs/api/elections/POST-elections-{election}-schedule.md
 * Tenant suite, route api/v1/elections/{election}/schedule: another institution's election answers 404, the same as an
 * unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const SCHEDULE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function scheduleUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/schedule";
}

/**
 * An owner of A signed in and a draft election with one ballot of two candidates and two voters (no warning).
 * Returns [team, election, ballot].
 */
function scheduleSignedIn($test, array $options = []): array
{
    $t = Team::two();
    $election = Accounts::plantElection($options + ['institution' => $t['a']['owner']['institution'], 'status' => 'draft', 'starts_at' => '2030-03-01 12:00:00', 'ends_at' => '2030-03-05 19:00:00']);
    $ballot = Accounts::plantBallot(['election' => $election, 'title' => 'Président']);
    Accounts::plantCandidate(['ballot' => $ballot, 'last_name' => 'Un']);
    Accounts::plantCandidate(['ballot' => $ballot, 'last_name' => 'Deux']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Jean']);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $ballot];
}

it('schedules a ready draft [FR-ELEC-03] (scenario 1)', function () {
    [$t, $election] = scheduleSignedIn($this);

    $response = $this->browser->post(scheduleUrl($election))->assertOk();

    expect($response->json('data'))->toMatchArray(['id' => $election, 'status' => 'scheduled'])
        ->and(Accounts::electionRow($election)['status'])->toBe('scheduled');
});

it('schedules a manager\'s request too [FR-ELEC-03] (scenario 1)', function () {
    [$t, $election] = scheduleSignedIn($this);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->post(scheduleUrl($election))->assertOk()->assertJsonPath('data.status', 'scheduled');
});

it('needs accept_warnings when the checklist has warnings, and then schedules [FR-ELEC-04] (scenarios 2, 3)', function () {
    [$t, $election, $ballot] = scheduleSignedIn($this);
    $one = Accounts::plantBallot(['election' => $election, 'title' => 'Un seul']);
    Accounts::plantCandidate(['ballot' => $one, 'last_name' => 'Solo']);

    $refused = $this->browser->post(scheduleUrl($election))->assertStatus(409);
    expect($refused->json('error.code'))->toBe('checklist_warnings')
        ->and($refused->json('error.items.0.code'))->toBe('ballot_single_candidate')
        ->and(Accounts::electionRow($election)['status'])->toBe('draft');

    $this->browser->post(scheduleUrl($election), ['accept_warnings' => false])->assertStatus(409)->assertJsonPath('error.code', 'checklist_warnings');

    $this->browser->post(scheduleUrl($election), ['accept_warnings' => true])->assertOk()->assertJsonPath('data.status', 'scheduled');
});

it('answers 409 checklist_blocked with the blockers, even when warnings are accepted [FR-ELEC-04] (scenario 4)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'starts_at' => '2030-03-01 12:00:00', 'ends_at' => '2030-03-05 19:00:00']);
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->post(scheduleUrl($election), ['accept_warnings' => true])->assertStatus(409);

    expect($response->json('error.code'))->toBe('checklist_blocked')
        ->and(array_column($response->json('error.items'), 'code'))->toBe(['no_ballots', 'no_voters'])
        ->and(Accounts::electionRow($election)['status'])->toBe('draft');
});

it('answers 409 election_period_over when the end is not in the future [FR-ELEC-03] (scenario 5)', function () {
    [$t, $election] = scheduleSignedIn($this, ['starts_at' => '2020-03-01 12:00:00', 'ends_at' => '2020-03-05 19:00:00']);

    $this->browser->post(scheduleUrl($election))->assertStatus(409)->assertJsonPath('error.code', 'election_period_over');

    expect(Accounts::electionRow($election)['status'])->toBe('draft');
});

it('answers 409 election_not_editable for an election that is not a draft [FR-ELEC-03] (scenario 6)', function (string $status) {
    [$t, $election] = scheduleSignedIn($this, ['status' => $status]);

    $this->browser->post(scheduleUrl($election))->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('answers 422 when accept_warnings is not a boolean [FR-ELEC-03] (scenario 7)', function () {
    [$t, $election] = scheduleSignedIn($this);

    $response = $this->browser->post(scheduleUrl($election), ['accept_warnings' => 'oui'])->assertStatus(422);

    expect($response->json('error.fields.accept_warnings'))->toContain('boolean');
});

it('locks ballots, candidates and the election while scheduled, and leaves voters and groups editable [FR-ELEC-03] (scenario 1)', function () {
    [$t, $election, $ballot] = scheduleSignedIn($this);
    $this->browser->post(scheduleUrl($election))->assertOk();

    $this->browser->post("/api/v1/elections/{$election}/ballots", ['title' => 'Nouveau'])->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    $this->browser->post("/api/v1/ballots/{$ballot}/candidates", ['first_name' => 'A', 'last_name' => 'B', 'sex' => 'female'])->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    $this->browser->patch("/api/v1/elections/{$election}", ['title' => 'Changé'])->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    $this->browser->post("/api/v1/elections/{$election}/voters", ['full_name' => 'Nouvel électeur', 'group' => 'Nouveau groupe'])->assertCreated();
    $this->browser->post("/api/v1/elections/{$election}/groups", ['name' => 'Un groupe'])->assertCreated();
});

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 8)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->post(scheduleUrl(SCHEDULE_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(scheduleUrl($target))))->toBe($unknown);
    }
    expect(Accounts::electionRow($foreign)['status'])->toBe('draft');
});

it('answers 401, 403, 419 and 405 as every write [NFR-SEC-04] (scenarios 9 to 11 and 13)', function () {
    [$t, $election] = scheduleSignedIn($this);
    $this->browser->forgetCookies();

    $this->browser->post(scheduleUrl($election))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->post(scheduleUrl($election), [], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('GET', scheduleUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->post(scheduleUrl($election))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::electionRow($election)['status'])->toBe('draft');
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 12)', function () {
    [$t, $election] = scheduleSignedIn($this);
    foreach (range(1, 120) as $i) {
        $this->browser->post(scheduleUrl($election), ['accept_warnings' => 'x'])->assertStatus(422);
    }

    $this->browser->post(scheduleUrl($election))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
