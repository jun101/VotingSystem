<?php

/*
 * POST /api/v1/ballots/{ballot}/candidates — docs/api/candidates/POST-ballots-{ballot}-candidates.md
 * Tenant suite, route api/v1/ballots/{ballot}/candidates: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const CAND_CREATE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function candCreateUrl(string $uuid): string
{
    return "/api/v1/ballots/{$uuid}/candidates";
}

/** An owner of A signed in, a draft (or the given status) with one ballot and one party. Returns [team, election, ballot, party]. */
function candCreateSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $ballot = Accounts::plantBallot(['election' => $election, 'title' => 'Président(e)']);
    $party = Accounts::plantParty(['election' => $election, 'name' => 'Ensemble']);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $ballot, $party];
}

function candBody(array $over = []): array
{
    return $over + ['first_name' => 'Nadège', 'last_name' => 'Pierre-Louis', 'sex' => 'female'];
}

it('creates an independent candidate from names and sex [FR-CAND-02, FR-CAND-03] (scenario 1)', function () {
    [$t, $election, $ballot] = candCreateSignedIn($this);

    $response = $this->browser->post(candCreateUrl($ballot), candBody(['first_name' => '  Nadège ']))->assertCreated();

    $row = Accounts::candidateRow($response->json('data.id'));
    expect($response->json('data'))->toMatchArray(['ballot' => $ballot, 'party' => null, 'first_name' => 'Nadège', 'last_name' => 'Pierre-Louis', 'sex' => 'female', 'slogan' => null, 'biography' => null, 'photo' => null, 'position' => 1])
        ->and($response->headers->get('Location'))->toBe('/api/v1/candidates/'.$response->json('data.id'))
        ->and($row['institution_id'])->toBe(Accounts::electionRow($election)['institution_id'])
        ->and($row['election_id'])->toBe(Accounts::electionRow($election)['id'])
        ->and(array_keys($response->json('data')))->toEqualCanonicalizing(['id', 'ballot', 'party', 'first_name', 'last_name', 'sex', 'slogan', 'biography', 'photo', 'position', 'created_at', 'updated_at']);
});

it('takes a party, a slogan and a biography and goes last in the ballot [FR-CAND-02] (scenario 2)', function () {
    [$t, $election, $ballot, $party] = candCreateSignedIn($this);
    Accounts::plantCandidate(['ballot' => $ballot, 'position' => 1]);

    $response = $this->browser->post(candCreateUrl($ballot), candBody(['party' => $party, 'slogan' => ' Une école qui nous écoute ', 'biography' => 'Élève de 4e année.', 'sex' => 'male']))->assertCreated();

    expect($response->json('data'))->toMatchArray(['party' => $party, 'slogan' => 'Une école qui nous écoute', 'biography' => 'Élève de 4e année.', 'sex' => 'male', 'position' => 2]);
    $this->browser->get("/api/v1/elections/{$election}/parties")->assertOk()->assertJsonPath('data.0.candidates_count', 1);
    $this->browser->get("/api/v1/elections/{$election}/ballots")->assertOk()->assertJsonPath('data.0.candidates_count', 2);
});

it('lets a manager add a candidate too [FR-CAND-02] (scenario 1)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $ballot = Accounts::plantBallot(['election' => $election]);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->post(candCreateUrl($ballot), candBody())->assertCreated();
});

it('answers 422 for a missing, blank or too long name [FR-CAND-02] (scenario 3)', function (array $body, string $field, string $rule) {
    [$t, $election, $ballot] = candCreateSignedIn($this);

    $response = $this->browser->post(candCreateUrl($ballot), $body + candBody());

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json("error.fields.{$field}"))->toContain($rule)
        ->and(Accounts::candidateRows($ballot))->toBe([]);
})->with([
    'first missing' => [['first_name' => null], 'first_name', 'required'],
    'first blank' => [['first_name' => '  '], 'first_name', 'required'],
    'last blank' => [['last_name' => ''], 'last_name', 'required'],
    'first too long' => [['first_name' => str_repeat('é', 81)], 'first_name', 'max'],
    'last too long' => [['last_name' => str_repeat('é', 81)], 'last_name', 'max'],
]);

it('answers 422 for a sex that is missing or not male or female [FR-CAND-03] (scenario 4)', function (mixed $sex, string $rule) {
    [$t, $election, $ballot] = candCreateSignedIn($this);

    $response = $this->browser->post(candCreateUrl($ballot), ['sex' => $sex] + candBody());

    $response->assertStatus(422);
    expect($response->json('error.fields.sex'))->toContain($rule)
        ->and(Accounts::candidateRows($ballot))->toBe([]);
})->with([[null, 'required'], ['other', 'in'], ['M', 'in']]);

it('answers 422 for a party that is not a UUID, and the same 422 for an unknown, another election\'s or another institution\'s [FR-CAND-02] (scenarios 5, 6)', function () {
    [$t, $election, $ballot] = candCreateSignedIn($this);
    $otherElection = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $sameInstitution = Accounts::plantParty(['election' => $otherElection]);
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantParty(['election' => $foreignElection]);

    $notUuid = $this->browser->post(candCreateUrl($ballot), candBody(['party' => 'abc']));
    $notUuid->assertStatus(422);
    expect($notUuid->json('error.fields.party'))->toContain('uuid');

    $unknown = Team::shape($this->browser->post(candCreateUrl($ballot), candBody(['party' => '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa'])));
    expect($unknown['status'])->toBe(422);
    foreach ([$sameInstitution, $foreign] as $party) {
        expect(Team::shape($this->browser->post(candCreateUrl($ballot), candBody(['party' => $party]))))->toBe($unknown);
    }
    expect(Accounts::candidateRows($ballot))->toBe([]);
});

it('answers 422 for a slogan over 80 or a biography over 1000, and stores blanks as null [FR-CAND-02] (scenario 7)', function () {
    [$t, $election, $ballot] = candCreateSignedIn($this);

    $response = $this->browser->post(candCreateUrl($ballot), candBody(['slogan' => str_repeat('é', 81), 'biography' => str_repeat('é', 1001)]));
    $response->assertStatus(422);
    expect($response->json('error.fields.slogan'))->toContain('max')
        ->and($response->json('error.fields.biography'))->toContain('max');

    $id = $this->browser->post(candCreateUrl($ballot), candBody(['slogan' => '  ', 'biography' => '']))->assertCreated()->json('data.id');
    expect(Accounts::candidateRow($id)['slogan'])->toBeNull()
        ->and(Accounts::candidateRow($id)['biography'])->toBeNull();
});

it('answers 409 candidate_limit_reached at 50 candidates in the ballot [FR-CAND-02] (scenario 8)', function () {
    [$t, $election, $ballot] = candCreateSignedIn($this);
    foreach (range(1, 50) as $i) {
        Accounts::plantCandidate(['ballot' => $ballot, 'position' => $i]);
    }

    $this->browser->post(candCreateUrl($ballot), candBody())->assertStatus(409)->assertJsonPath('error.code', 'candidate_limit_reached');
    expect(Accounts::candidateRows($ballot))->toHaveCount(50);
});

it('answers 409 for an election that is not a draft, and adds nothing [FR-SEC-06] (scenario 9)', function (string $status) {
    [$t, $election, $ballot] = candCreateSignedIn($this, $status);

    $this->browser->post(candCreateUrl($ballot), candBody())->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(Accounts::candidateRows($ballot))->toBe([]);
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('checks the body before the state [FR-SEC-06] (scenario 9)', function () {
    [$t, $election, $ballot] = candCreateSignedIn($this, 'open');

    $this->browser->post(candCreateUrl($ballot), ['sex' => 'x'])->assertStatus(422);
});

it('answers 404, the same for every case, for a ballot that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 10)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantBallot(['election' => $foreignElection]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->post(candCreateUrl(CAND_CREATE_UNKNOWN), candBody()));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');
    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(candCreateUrl($target), candBody())))->toBe($unknown);
    }
    expect(Accounts::candidateRows($foreign))->toBe([]);
});

it('answers 401 when nobody is signed in [FR-CAND-02] (scenario 11)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $ballot = Accounts::plantBallot(['election' => $election]);

    $this->browser->post(candCreateUrl($ballot), candBody())->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 12)', function () {
    [$t, $election, $ballot] = candCreateSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->post(candCreateUrl($ballot), candBody())->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::candidateRows($ballot))->toBe([]);
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 13)', function () {
    [$t, $election, $ballot] = candCreateSignedIn($this);

    $this->browser->post(candCreateUrl($ballot), candBody(), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 14)', function () {
    [$t, $election, $ballot] = candCreateSignedIn($this);

    $this->browser->rawBody('POST', candCreateUrl($ballot), '{"first_name": "X"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 15)', function () {
    [$t, $election, $ballot] = candCreateSignedIn($this);
    foreach (range(1, 120) as $i) {
        $this->browser->post(candCreateUrl($ballot), ['sex' => 'x'])->assertStatus(422);
    }

    $response = $this->browser->post(candCreateUrl($ballot), candBody());

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(Accounts::candidateRows($ballot))->toBe([]);
});

it('answers 405 for another method than GET, HEAD, POST [FR-CAND-02] (scenario 16)', function (string $method) {
    [$t, $election, $ballot] = candCreateSignedIn($this);

    $this->browser->other($method, candCreateUrl($ballot))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['PATCH', 'DELETE']);

it('ignores position, id, election, institution and photo in the body [FR-INST-05] (notes)', function () {
    [$t, $election, $ballot] = candCreateSignedIn($this);

    $response = $this->browser->post(candCreateUrl($ballot), candBody([
        'position' => 9, 'id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'institution' => $t['b']['owner']['institution'],
        'election_id' => 1, 'ballot_id' => 1, 'photo' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'photo_file' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
    ]))->assertCreated();

    $row = Accounts::candidateRow($response->json('data.id'));
    expect($response->json('data.position'))->toBe(1)
        ->and($response->json('data.id'))->not->toBe('7c9e6679-7425-40de-944b-e07fc1f90ae7')
        ->and($response->json('data.photo'))->toBeNull()
        ->and($row['photo_file'])->toBeNull();
});
