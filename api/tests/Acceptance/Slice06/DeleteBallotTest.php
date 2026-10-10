<?php

/*
 * DELETE /api/v1/ballots/{ballot} — docs/api/ballots/DELETE-ballots-{ballot}.md
 * Tenant suite, route api/v1/ballots/{ballot}: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const DELETE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

/** An owner of A signed in, a draft of A (or the given status) and one ballot in it. Returns [team, election, ballot]. */
function deleteSignedIn($test, string $status = 'draft', array $ballot = []): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $id = Accounts::plantBallot($ballot + ['election' => $election, 'title' => 'Avant']);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $id];
}

function deleteBallotUrl(string $uuid): string
{
    return "/api/v1/ballots/{$uuid}";
}

it('deletes a ballot of a draft and closes the gap in the positions [FR-BAL-01] (scenario 1)', function () {
    [$t, $election, $first] = deleteSignedIn($this, 'draft', ['title' => 'Un', 'position' => 1]);
    $second = Accounts::plantBallot(['election' => $election, 'title' => 'Deux', 'position' => 2]);
    $third = Accounts::plantBallot(['election' => $election, 'title' => 'Trois', 'position' => 3]);

    $this->browser->delete(deleteBallotUrl($second))->assertNoContent();

    expect(Accounts::ballotRow($second))->toBeNull();
    $rows = Accounts::ballotRows($election);
    expect(array_column($rows, 'uuid'))->toBe([$first, $third])
        ->and(array_column($rows, 'position'))->toBe([1, 2]);
    $this->browser->get('/api/v1/elections/'.$election)->assertOk()->assertJsonPath('data.ballots_count', 2);
});

it('lets a manager delete a ballot too [FR-BAL-01] (scenario 1)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $id = Accounts::plantBallot(['election' => $election]);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->delete(deleteBallotUrl($id))->assertNoContent();
});

it('leaves the other ballots of the election and of other elections alone [FR-INST-05] (scenario 1)', function () {
    [$t, $election, $id] = deleteSignedIn($this);
    $other = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $kept = Accounts::plantBallot(['election' => $other, 'title' => 'Ailleurs']);

    $this->browser->delete(deleteBallotUrl($id))->assertNoContent();

    expect(Accounts::ballotRow($kept)['title'])->toBe('Ailleurs')
        ->and(Accounts::ballotRows($election))->toBe([]);
});

it('answers 409 for an election that is not a draft, and keeps the ballot [FR-SEC-06] (scenario 2)', function (string $status) {
    [$t, $election, $id] = deleteSignedIn($this, $status);

    $response = $this->browser->delete(deleteBallotUrl($id));

    $response->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(Accounts::ballotRow($id))->not->toBeNull();
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for a ballot that is unknown, deleted, of another institution or not a UUID [FR-INST-05] (scenario 3)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantBallot(['election' => $foreignElection, 'title' => 'De B']);
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $gone = Accounts::plantBallot(['election' => $election]);
    Team::signIn($this, $t['a']['owner']);
    $this->browser->delete(deleteBallotUrl($gone))->assertNoContent();

    $unknown = Team::shape($this->browser->delete(deleteBallotUrl(DELETE_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, $gone, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->delete(deleteBallotUrl($target))))->toBe($unknown);
    }
    expect(Accounts::ballotRow($foreign)['title'])->toBe('De B');
});

it('answers 401 when nobody is signed in [FR-BAL-01] (scenario 4)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $id = Accounts::plantBallot(['election' => $election]);

    $this->browser->delete(deleteBallotUrl($id))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::ballotRow($id))->not->toBeNull();
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 5)', function () {
    [$t, $election, $id] = deleteSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->delete(deleteBallotUrl($id))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::ballotRow($id))->not->toBeNull();
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 6)', function () {
    [$t, $election, $id] = deleteSignedIn($this);

    $this->browser->delete(deleteBallotUrl($id), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Accounts::ballotRow($id))->not->toBeNull();
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 7)', function () {
    [$t, $election, $first] = deleteSignedIn($this);
    $ids = [$first];
    foreach (range(2, 121) as $i) {
        $ids[] = Accounts::plantBallot(['election' => $election, 'title' => "Poste {$i}"]);
    }

    foreach (array_slice($ids, 0, 120) as $id) {
        $this->browser->delete(deleteBallotUrl($id))->assertNoContent();
    }

    $response = $this->browser->delete(deleteBallotUrl($ids[120]));

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect(Accounts::ballotRow($ids[120]))->not->toBeNull();
});

it('answers 405 for another method than PATCH and DELETE [FR-BAL-01] (scenario 8)', function (string $method) {
    [$t, $election, $id] = deleteSignedIn($this);

    $this->browser->other($method, deleteBallotUrl($id))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect(Accounts::ballotRow($id))->not->toBeNull();
})->with(['GET', 'POST', 'PUT']);
