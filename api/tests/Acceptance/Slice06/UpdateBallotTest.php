<?php

/*
 * PATCH /api/v1/ballots/{ballot} — docs/api/ballots/PATCH-ballots-{ballot}.md
 * Tenant suite, route api/v1/ballots/{ballot}: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const PATCH_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

/** An owner of A signed in, a draft of A (or the given status) and one ballot in it. Returns [team, election, ballot]. */
function patchSignedIn($test, string $status = 'draft', array $ballot = []): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $id = Accounts::plantBallot($ballot + ['election' => $election, 'title' => 'Avant']);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $id];
}

function patchBallotUrl(string $uuid): string
{
    return "/api/v1/ballots/{$uuid}";
}

it('changes the fields that are sent and only those [FR-BAL-01, FR-BAL-02] (scenario 1)', function () {
    [$t, $election, $id] = patchSignedIn($this, 'draft', ['description' => 'Avant aussi', 'seats' => 1, 'position' => 1]);
    $before = Accounts::ballotRow($id);

    $response = $this->browser->patch(patchBallotUrl($id), ['title' => '  Après  ', 'seats' => 3, 'allow_blank' => false])->assertOk();

    $after = Accounts::ballotRow($id);
    expect($response->json('data'))->toMatchArray(['id' => $id, 'title' => 'Après', 'seats' => 3, 'allow_blank' => false, 'description' => 'Avant aussi', 'position' => 1])
        ->and($after['id'])->toBe($before['id'])
        ->and($after['election_id'])->toBe($before['election_id'])
        ->and($after['institution_id'])->toBe($before['institution_id'])
        ->and($after['created_at'])->toBe($before['created_at']);
});

it('lets a manager change a ballot too [FR-BAL-01] (scenario 1)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $id = Accounts::plantBallot(['election' => $election]);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->patch(patchBallotUrl($id), ['title' => 'Par le gestionnaire'])->assertOk()->assertJsonPath('data.title', 'Par le gestionnaire');
});

it('answers 200 and changes nothing for an empty body or no known field [FR-BAL-01] (scenario 2)', function (array $body) {
    [$t, $election, $id] = patchSignedIn($this);
    $before = Accounts::ballotRow($id);

    $this->browser->patch(patchBallotUrl($id), $body)->assertOk()->assertJsonPath('data.title', 'Avant');

    $after = Accounts::ballotRow($id);
    unset($before['updated_at'], $after['updated_at']);
    expect($after)->toBe($before);
})->with([[[]], [['colour' => 'blue']]]);

it('stores a description sent as an empty string or null as null [FR-BAL-01] (scenario 3)', function (mixed $value) {
    [$t, $election, $id] = patchSignedIn($this, 'draft', ['description' => 'À effacer']);

    $this->browser->patch(patchBallotUrl($id), ['description' => $value])->assertOk()->assertJsonPath('data.description', null);

    expect(Accounts::ballotRow($id)['description'])->toBeNull();
})->with(['', '   ', null]);

it('answers 422 when the title is blank, null or too long [FR-BAL-01] (scenario 4)', function (mixed $value, string $rule) {
    [$t, $election, $id] = patchSignedIn($this);

    $response = $this->browser->patch(patchBallotUrl($id), ['title' => $value]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.title'))->toContain($rule)
        ->and(Accounts::ballotRow($id)['title'])->toBe('Avant');
})->with([[' ', 'required'], [null, 'required'], [str_repeat('é', 201), 'max']]);

it('answers 422 for the other rules of the creation [FR-BAL-02] (scenario 5)', function (array $body, string $field, string $rule) {
    [$t, $election, $id] = patchSignedIn($this);

    $response = $this->browser->patch(patchBallotUrl($id), $body);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json("error.fields.{$field}"))->toContain($rule)
        ->and(Accounts::ballotRow($id)['seats'])->toBe(1);
})->with([
    'seats above 20' => [['seats' => 21], 'seats', 'max'],
    'seats zero' => [['seats' => 0], 'seats', 'min'],
    'allow_blank text' => [['allow_blank' => 'maybe'], 'allow_blank', 'boolean'],
    'description too long' => [['description' => str_repeat('é', 1001)], 'description', 'max'],
]);

it('answers 409 for an election that is not a draft, and changes nothing [FR-SEC-06] (scenario 6)', function (string $status) {
    [$t, $election, $id] = patchSignedIn($this, $status);

    $response = $this->browser->patch(patchBallotUrl($id), ['title' => 'Après']);

    $response->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(Accounts::ballotRow($id)['title'])->toBe('Avant');
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('checks the body before the state [FR-SEC-06] (scenario 6)', function () {
    [$t, $election, $id] = patchSignedIn($this, 'open');

    $this->browser->patch(patchBallotUrl($id), ['title' => ''])->assertStatus(422);
});

it('answers 404, the same for every case, for a ballot that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 7)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantBallot(['election' => $foreignElection, 'title' => 'De B']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->patch(patchBallotUrl(PATCH_UNKNOWN), ['title' => 'X']));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->patch(patchBallotUrl($target), ['title' => 'X'])))->toBe($unknown);
    }
    expect(Team::shape($this->browser->patch(patchBallotUrl($foreign), ['title' => ''])))->toBe($unknown)
        ->and(Accounts::ballotRow($foreign)['title'])->toBe('De B');
});

it('answers 401 when nobody is signed in [FR-BAL-01] (scenario 8)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $id = Accounts::plantBallot(['election' => $election, 'title' => 'Avant']);

    $this->browser->patch(patchBallotUrl($id), ['title' => 'Après'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::ballotRow($id)['title'])->toBe('Avant');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 9)', function () {
    [$t, $election, $id] = patchSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->patch(patchBallotUrl($id), ['title' => 'Après'])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::ballotRow($id)['title'])->toBe('Avant');
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 10)', function () {
    [$t, $election, $id] = patchSignedIn($this);

    $this->browser->patch(patchBallotUrl($id), ['title' => 'Après'], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 11)', function () {
    [$t, $election, $id] = patchSignedIn($this);

    $this->browser->rawBody('PATCH', patchBallotUrl($id), '{"title": "X"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 12)', function () {
    [$t, $election, $id] = patchSignedIn($this);

    foreach (range(1, 120) as $i) {
        $this->browser->patch(patchBallotUrl($id), ['title' => "T{$i}"])->assertOk();
    }

    $response = $this->browser->patch(patchBallotUrl($id), ['title' => 'Trop']);

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect(Accounts::ballotRow($id)['title'])->toBe('T120');
});

it('answers 405 for another method than PATCH and DELETE [FR-BAL-01] (scenario 13)', function (string $method) {
    [$t, $election, $id] = patchSignedIn($this);

    $this->browser->other($method, patchBallotUrl($id))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['GET', 'POST', 'PUT']);

it('ignores position, id, election and candidates_count in the body [FR-INST-05] (notes)', function () {
    [$t, $election, $id] = patchSignedIn($this, 'draft', ['position' => 1]);
    $before = Accounts::ballotRow($id);

    $this->browser->patch(patchBallotUrl($id), [
        'title' => 'Après', 'position' => 7, 'id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
        'election' => $t['b']['owner']['institution'], 'election_id' => 99, 'candidates_count' => 5, 'scope' => 'group',
    ])->assertOk();

    $after = Accounts::ballotRow($id);
    expect($after['title'])->toBe('Après')
        ->and($after['position'])->toBe($before['position'])
        ->and($after['uuid'])->toBe($before['uuid'])
        ->and($after['election_id'])->toBe($before['election_id'])
        ->and($after['scope'])->toBe('general');
});
