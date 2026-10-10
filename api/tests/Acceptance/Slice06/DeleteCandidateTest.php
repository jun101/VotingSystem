<?php

/*
 * DELETE /api/v1/candidates/{candidate} — docs/api/candidates/DELETE-candidates-{candidate}.md
 * Tenant suite, route api/v1/candidates/{candidate}: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const CAND_DELETE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function candDeleteUrl(string $uuid): string
{
    return "/api/v1/candidates/{$uuid}";
}

function candDeleteSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $ballot = Accounts::plantBallot(['election' => $election]);
    $id = Accounts::plantCandidate(['ballot' => $ballot, 'position' => 1]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $ballot, $id];
}

it('deletes a candidate of a draft and closes the positions after it [FR-CAND-02] (scenario 1)', function () {
    [$t, $election, $ballot, $first] = candDeleteSignedIn($this);
    $second = Accounts::plantCandidate(['ballot' => $ballot, 'position' => 2]);
    $third = Accounts::plantCandidate(['ballot' => $ballot, 'position' => 3]);

    $this->browser->delete(candDeleteUrl($second))->assertNoContent();

    $rows = Accounts::candidateRows($ballot);
    expect(Accounts::candidateRow($second))->toBeNull()
        ->and(array_column($rows, 'uuid'))->toBe([$first, $third])
        ->and(array_column($rows, 'position'))->toBe([1, 2]);
    $this->browser->get("/api/v1/elections/{$election}/ballots")->assertOk()->assertJsonPath('data.0.candidates_count', 2);
});

it('lets a manager delete a candidate too [FR-CAND-02] (scenario 1)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $id = Accounts::plantCandidate(['ballot' => Accounts::plantBallot(['election' => $election])]);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->delete(candDeleteUrl($id))->assertNoContent();
});

it('answers 409 for an election that is not a draft, and keeps the candidate [FR-SEC-06] (scenario 2)', function (string $status) {
    [$t, $election, $ballot, $id] = candDeleteSignedIn($this, $status);

    $this->browser->delete(candDeleteUrl($id))->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(Accounts::candidateRow($id))->not->toBeNull();
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for a candidate that is unknown, deleted, of another institution or not a UUID [FR-INST-05] (scenario 3)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantCandidate(['ballot' => Accounts::plantBallot(['election' => $foreignElection]), 'first_name' => 'DeB']);
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $gone = Accounts::plantCandidate(['ballot' => Accounts::plantBallot(['election' => $election])]);
    Team::signIn($this, $t['a']['owner']);
    $this->browser->delete(candDeleteUrl($gone))->assertNoContent();

    $unknown = Team::shape($this->browser->delete(candDeleteUrl(CAND_DELETE_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');
    foreach ([$foreign, $gone, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->delete(candDeleteUrl($target))))->toBe($unknown);
    }
    expect(Accounts::candidateRow($foreign)['first_name'])->toBe('DeB');
});

it('answers 401 when nobody is signed in [FR-CAND-02] (scenario 4)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $id = Accounts::plantCandidate(['ballot' => Accounts::plantBallot(['election' => $election])]);

    $this->browser->delete(candDeleteUrl($id))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::candidateRow($id))->not->toBeNull();
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 5)', function () {
    [$t, $election, $ballot, $id] = candDeleteSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->delete(candDeleteUrl($id))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::candidateRow($id))->not->toBeNull();
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 6)', function () {
    [$t, $election, $ballot, $id] = candDeleteSignedIn($this);

    $this->browser->delete(candDeleteUrl($id), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Accounts::candidateRow($id))->not->toBeNull();
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 7)', function () {
    [$t, $election, $ballot, $first] = candDeleteSignedIn($this);
    $ids = [$first];
    foreach (range(2, 121) as $i) {
        $ids[] = Accounts::plantCandidate(['ballot' => $ballot, 'position' => $i]);
    }
    foreach (array_slice($ids, 0, 120) as $id) {
        $this->browser->delete(candDeleteUrl($id))->assertNoContent();
    }

    $this->browser->delete(candDeleteUrl($ids[120]))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect(Accounts::candidateRow($ids[120]))->not->toBeNull();
});

it('answers 405 for another method than PATCH and DELETE [FR-CAND-02] (scenario 8)', function (string $method) {
    [$t, $election, $ballot, $id] = candDeleteSignedIn($this);

    $this->browser->other($method, candDeleteUrl($id))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect(Accounts::candidateRow($id))->not->toBeNull();
})->with(['GET', 'POST', 'PUT']);
