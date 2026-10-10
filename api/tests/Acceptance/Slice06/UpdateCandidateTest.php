<?php

/*
 * PATCH /api/v1/candidates/{candidate} — docs/api/candidates/PATCH-candidates-{candidate}.md
 * Tenant suite, route api/v1/candidates/{candidate}: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const CAND_PATCH_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function candPatchUrl(string $uuid): string
{
    return "/api/v1/candidates/{$uuid}";
}

/** Owner of A signed in; a draft with ballots one and two, a party, and a candidate in ballot one. Returns [team, election, one, two, party, candidate]. */
function candPatchSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $one = Accounts::plantBallot(['election' => $election, 'title' => 'Un', 'position' => 1]);
    $two = Accounts::plantBallot(['election' => $election, 'title' => 'Deux', 'position' => 2]);
    $party = Accounts::plantParty(['election' => $election, 'name' => 'Ensemble']);
    $candidate = Accounts::plantCandidate(['ballot' => $one, 'first_name' => 'Avant', 'last_name' => 'Nom', 'sex' => 'female', 'party' => $party, 'slogan' => 'Mot', 'position' => 1]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $one, $two, $party, $candidate];
}

it('changes the fields that are sent and only those [FR-CAND-02] (scenario 1)', function () {
    [$t, $election, $one, $two, $party, $id] = candPatchSignedIn($this);
    $before = Accounts::candidateRow($id);

    $response = $this->browser->patch(candPatchUrl($id), ['first_name' => '  Après ', 'sex' => 'male', 'biography' => 'Bio'])->assertOk();

    $after = Accounts::candidateRow($id);
    expect($response->json('data'))->toMatchArray(['id' => $id, 'first_name' => 'Après', 'last_name' => 'Nom', 'sex' => 'male', 'slogan' => 'Mot', 'biography' => 'Bio', 'party' => $party, 'ballot' => $one, 'position' => 1])
        ->and($after['id'])->toBe($before['id'])
        ->and($after['created_at'])->toBe($before['created_at']);
});

it('lets a manager change a candidate too [FR-CAND-02] (scenario 1)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $ballot = Accounts::plantBallot(['election' => $election]);
    $id = Accounts::plantCandidate(['ballot' => $ballot]);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->patch(candPatchUrl($id), ['first_name' => 'Gestionnaire'])->assertOk();
});

it('answers 200 and changes nothing for an empty body or no known field [FR-CAND-02] (scenario 2)', function (array $body) {
    [$t, $election, $one, $two, $party, $id] = candPatchSignedIn($this);
    $before = Accounts::candidateRow($id);

    $this->browser->patch(candPatchUrl($id), $body)->assertOk()->assertJsonPath('data.first_name', 'Avant');

    $after = Accounts::candidateRow($id);
    unset($before['updated_at'], $after['updated_at']);
    expect($after)->toBe($before);
})->with([[[]], [['colour' => 'blue']]]);

it('clears the party with null and stores blank slogan and biography as null [FR-CAND-02] (scenario 3)', function () {
    [$t, $election, $one, $two, $party, $id] = candPatchSignedIn($this);

    $this->browser->patch(candPatchUrl($id), ['party' => null, 'slogan' => ' ', 'biography' => ''])->assertOk()->assertJsonPath('data.party', null);

    $row = Accounts::candidateRow($id);
    expect($row['party_id'])->toBeNull()->and($row['slogan'])->toBeNull();
});

it('changes the party to another party of the election [FR-CAND-02] (scenario 4)', function () {
    [$t, $election, $one, $two, $party, $id] = candPatchSignedIn($this);
    $other = Accounts::plantParty(['election' => $election, 'name' => 'Avenir']);

    $this->browser->patch(candPatchUrl($id), ['party' => $other])->assertOk()->assertJsonPath('data.party', $other);
});

it('moves a candidate to the end of another ballot of the election and closes the positions it leaves [FR-CAND-02] (scenario 5)', function () {
    [$t, $election, $one, $two, $party, $first] = candPatchSignedIn($this);
    $second = Accounts::plantCandidate(['ballot' => $one, 'position' => 2]);
    $third = Accounts::plantCandidate(['ballot' => $one, 'position' => 3]);
    $already = Accounts::plantCandidate(['ballot' => $two, 'position' => 1]);

    $response = $this->browser->patch(candPatchUrl($first), ['ballot' => $two])->assertOk();

    expect($response->json('data.ballot'))->toBe($two)
        ->and($response->json('data.position'))->toBe(2)
        ->and(array_column(Accounts::candidateRows($one), 'uuid'))->toBe([$second, $third])
        ->and(array_column(Accounts::candidateRows($one), 'position'))->toBe([1, 2])
        ->and(array_column(Accounts::candidateRows($two), 'uuid'))->toBe([$already, $first]);
});

it('accepts the ballot it is already in and changes nothing [FR-CAND-02] (scenario 5)', function () {
    [$t, $election, $one, $two, $party, $id] = candPatchSignedIn($this);

    $this->browser->patch(candPatchUrl($id), ['ballot' => $one])->assertOk()->assertJsonPath('data.position', 1);
});

it('answers 422 for a bad name, sex or text length [FR-CAND-02] (scenario 6)', function (array $body, string $field, string $rule) {
    [$t, $election, $one, $two, $party, $id] = candPatchSignedIn($this);

    $response = $this->browser->patch(candPatchUrl($id), $body);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json("error.fields.{$field}"))->toContain($rule)
        ->and(Accounts::candidateRow($id)['first_name'])->toBe('Avant');
})->with([
    'first blank' => [['first_name' => ' '], 'first_name', 'required'],
    'last null' => [['last_name' => null], 'last_name', 'required'],
    'last too long' => [['last_name' => str_repeat('é', 81)], 'last_name', 'max'],
    'sex' => [['sex' => 'x'], 'sex', 'in'],
    'slogan' => [['slogan' => str_repeat('é', 81)], 'slogan', 'max'],
    'biography' => [['biography' => str_repeat('é', 1001)], 'biography', 'max'],
]);

it('answers the same 422 for a party or ballot that is unknown, of another election or of another institution [FR-CAND-02] (scenario 7)', function (string $field) {
    [$t, $election, $one, $two, $party, $id] = candPatchSignedIn($this);
    $otherElection = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $targets = $field === 'party'
        ? [Accounts::plantParty(['election' => $otherElection]), Accounts::plantParty(['election' => $foreignElection])]
        : [Accounts::plantBallot(['election' => $otherElection]), Accounts::plantBallot(['election' => $foreignElection])];

    $unknown = Team::shape($this->browser->patch(candPatchUrl($id), [$field => '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa']));
    expect($unknown['status'])->toBe(422);
    foreach ($targets as $target) {
        expect(Team::shape($this->browser->patch(candPatchUrl($id), [$field => $target])))->toBe($unknown);
    }
    expect(Accounts::candidateRow($id)['ballot_id'])->toBe(Accounts::ballotRow($one)['id']);
})->with(['party', 'ballot']);

it('answers 409 candidate_limit_reached when the target ballot is full [FR-CAND-02] (scenario 8)', function () {
    [$t, $election, $one, $two, $party, $id] = candPatchSignedIn($this);
    foreach (range(1, 50) as $i) {
        Accounts::plantCandidate(['ballot' => $two, 'position' => $i]);
    }

    $this->browser->patch(candPatchUrl($id), ['ballot' => $two])->assertStatus(409)->assertJsonPath('error.code', 'candidate_limit_reached');
    expect(Accounts::candidateRow($id)['ballot_id'])->toBe(Accounts::ballotRow($one)['id']);
});

it('answers 409 for an election that is not a draft, and changes nothing [FR-SEC-06] (scenario 9)', function (string $status) {
    [$t, $election, $one, $two, $party, $id] = candPatchSignedIn($this, $status);

    $this->browser->patch(candPatchUrl($id), ['first_name' => 'Après'])->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(Accounts::candidateRow($id)['first_name'])->toBe('Avant');
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for a candidate that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 10)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreignBallot = Accounts::plantBallot(['election' => $foreignElection]);
    $foreign = Accounts::plantCandidate(['ballot' => $foreignBallot, 'first_name' => 'DeB']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->patch(candPatchUrl(CAND_PATCH_UNKNOWN), ['first_name' => 'X']));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');
    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->patch(candPatchUrl($target), ['first_name' => 'X'])))->toBe($unknown);
    }
    expect(Team::shape($this->browser->patch(candPatchUrl($foreign), ['first_name' => ''])))->toBe($unknown)
        ->and(Accounts::candidateRow($foreign)['first_name'])->toBe('DeB');
});

it('answers 401 when nobody is signed in [FR-CAND-02] (scenario 11)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $id = Accounts::plantCandidate(['ballot' => Accounts::plantBallot(['election' => $election]), 'first_name' => 'Avant']);

    $this->browser->patch(candPatchUrl($id), ['first_name' => 'Après'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::candidateRow($id)['first_name'])->toBe('Avant');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 12)', function () {
    [$t, $election, $one, $two, $party, $id] = candPatchSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->patch(candPatchUrl($id), ['first_name' => 'Après'])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 13)', function () {
    [$t, $election, $one, $two, $party, $id] = candPatchSignedIn($this);

    $this->browser->patch(candPatchUrl($id), ['first_name' => 'Après'], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 14)', function () {
    [$t, $election, $one, $two, $party, $id] = candPatchSignedIn($this);

    $this->browser->rawBody('PATCH', candPatchUrl($id), '{"first_name": "X"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 15)', function () {
    [$t, $election, $one, $two, $party, $id] = candPatchSignedIn($this);
    foreach (range(1, 120) as $i) {
        $this->browser->patch(candPatchUrl($id), ['first_name' => "T{$i}"])->assertOk();
    }

    $this->browser->patch(candPatchUrl($id), ['first_name' => 'Trop'])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect(Accounts::candidateRow($id)['first_name'])->toBe('T120');
});

it('answers 405 for another method than PATCH and DELETE [FR-CAND-02] (scenario 16)', function (string $method) {
    [$t, $election, $one, $two, $party, $id] = candPatchSignedIn($this);

    $this->browser->other($method, candPatchUrl($id))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['GET', 'POST', 'PUT']);

it('ignores id, position and photo in the body [FR-INST-05] (notes)', function () {
    [$t, $election, $one, $two, $party, $id] = candPatchSignedIn($this);
    $before = Accounts::candidateRow($id);

    $this->browser->patch(candPatchUrl($id), ['first_name' => 'Après', 'id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'position' => 7, 'photo' => '7c9e6679-7425-40de-944b-e07fc1f90ae7'])->assertOk();

    $after = Accounts::candidateRow($id);
    expect($after['uuid'])->toBe($before['uuid'])->and($after['position'])->toBe($before['position'])->and($after['photo_file'])->toBeNull();
});
