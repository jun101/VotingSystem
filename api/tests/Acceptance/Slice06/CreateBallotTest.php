<?php

/*
 * POST /api/v1/elections/{election}/ballots — docs/api/ballots/POST-elections-{election}-ballots.md
 * Tenant suite, route api/v1/elections/{election}/ballots: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const CREATE_UNKNOWN_ELECTION = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function createUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/ballots";
}

function createSignedIn($test, array $options = []): array
{
    $t = Team::two();
    $election = Accounts::plantElection($options + ['institution' => $t['a']['owner']['institution']]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('creates a ballot with defaults from a title alone [FR-BAL-01, FR-BAL-02] (scenario 1)', function () {
    [$t, $election] = createSignedIn($this);

    $response = $this->browser->post(createUrl($election), ['title' => '  Président(e)  '])->assertCreated();

    $row = Accounts::ballotRow($response->json('data.id'));
    expect($response->json('data'))->toMatchArray(['title' => 'Président(e)', 'description' => null, 'position' => 1, 'seats' => 1, 'allow_blank' => true, 'candidates_count' => 0])
        ->and($response->headers->get('Location'))->toBe('/api/v1/ballots/'.$response->json('data.id'))
        ->and($row['scope'])->toBe('general')
        ->and($row['institution_id'])->toBe(Accounts::electionRow($election)['institution_id'])
        ->and($row['election_id'])->toBe(Accounts::electionRow($election)['id']);
});

it('puts a new ballot after the last one and takes every field [FR-BAL-01, FR-BAL-02] (scenario 2)', function () {
    [$t, $election] = createSignedIn($this);
    Accounts::plantBallot(['election' => $election, 'position' => 1]);
    Accounts::plantBallot(['election' => $election, 'position' => 2]);

    $response = $this->browser->post(createUrl($election), ['title' => 'Délégué(e)', 'description' => 'Par classe', 'seats' => 3, 'allow_blank' => false])->assertCreated();

    expect($response->json('data'))->toMatchArray(['title' => 'Délégué(e)', 'description' => 'Par classe', 'position' => 3, 'seats' => 3, 'allow_blank' => false]);
    $this->browser->get('/api/v1/elections/'.$election)->assertOk()->assertJsonPath('data.ballots_count', 3);
});

it('lets a manager add a ballot too [FR-BAL-01] (scenario 1)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->post(createUrl($election), ['title' => 'Par le gestionnaire'])->assertCreated();
});

it('answers 422 when the title is missing, blank or too long [FR-BAL-01] (scenario 3)', function (array $body, string $rule) {
    [$t, $election] = createSignedIn($this);

    $response = $this->browser->post(createUrl($election), $body);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.title'))->toContain($rule)
        ->and(Accounts::ballotRows($election))->toBe([]);
})->with([
    'missing' => [[], 'required'],
    'blank' => [['title' => '   '], 'required'],
    'null' => [['title' => null], 'required'],
    'too long' => [['title' => str_repeat('é', 201)], 'max'],
]);

it('answers 422 when the description is longer than 1000 [FR-BAL-01] (scenario 4)', function () {
    [$t, $election] = createSignedIn($this);

    $response = $this->browser->post(createUrl($election), ['title' => 'X', 'description' => str_repeat('é', 1001)]);

    $response->assertStatus(422);
    expect($response->json('error.fields.description'))->toContain('max');
});

it('stores a blank description as null [FR-BAL-01] (scenario 2)', function () {
    [$t, $election] = createSignedIn($this);

    $id = $this->browser->post(createUrl($election), ['title' => 'X', 'description' => '   '])->assertCreated()->json('data.id');

    expect(Accounts::ballotRow($id)['description'])->toBeNull();
});

it('answers 422 for seats and allow_blank out of range or of the wrong type [FR-BAL-02] (scenario 5)', function (array $body, string $field, string $rule) {
    [$t, $election] = createSignedIn($this);

    $response = $this->browser->post(createUrl($election), ['title' => 'X'] + $body);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json("error.fields.{$field}"))->toContain($rule)
        ->and(Accounts::ballotRows($election))->toBe([]);
})->with([
    'seats zero' => [['seats' => 0], 'seats', 'min'],
    'seats above 20' => [['seats' => 21], 'seats', 'max'],
    'seats text' => [['seats' => 'two'], 'seats', 'integer'],
    'seats decimal' => [['seats' => 1.5], 'seats', 'integer'],
    'allow_blank text' => [['allow_blank' => 'maybe'], 'allow_blank', 'boolean'],
]);

it('answers 409 ballot_limit_reached at 50 ballots [FR-BAL-01] (scenario 6)', function () {
    [$t, $election] = createSignedIn($this);
    foreach (range(1, 50) as $i) {
        Accounts::plantBallot(['election' => $election, 'position' => $i]);
    }

    $response = $this->browser->post(createUrl($election), ['title' => 'Le 51e']);

    $response->assertStatus(409)->assertJsonPath('error.code', 'ballot_limit_reached');
    expect(Accounts::ballotRows($election))->toHaveCount(50);
});

it('answers 409 for an election that is not a draft, and adds nothing [FR-SEC-06] (scenario 7)', function (string $status) {
    [$t, $election] = createSignedIn($this, ['status' => $status]);

    $response = $this->browser->post(createUrl($election), ['title' => 'Trop tard']);

    $response->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message'])
        ->and(Accounts::ballotRows($election))->toBe([]);
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('checks the body before the state [FR-SEC-06] (scenario 7)', function () {
    [$t, $election] = createSignedIn($this, ['status' => 'open']);

    $this->browser->post(createUrl($election), ['title' => ''])->assertStatus(422);
});

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 8)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->post(createUrl(CREATE_UNKNOWN_ELECTION), ['title' => 'X']));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(createUrl($target), ['title' => 'X'])))->toBe($unknown);
    }
    expect(Team::shape($this->browser->post(createUrl($foreign), ['title' => ''])))->toBe($unknown)
        ->and(Accounts::ballotRows($foreign))->toBe([]);
});

it('answers 401 when nobody is signed in [FR-BAL-01] (scenario 9)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $this->browser->post(createUrl($election), ['title' => 'X'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::ballotRows($election))->toBe([]);
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 10)', function () {
    [$t, $election] = createSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->post(createUrl($election), ['title' => 'X'])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::ballotRows($election))->toBe([]);
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 11)', function () {
    [$t, $election] = createSignedIn($this);

    $this->browser->post(createUrl($election), ['title' => 'X'], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Accounts::ballotRows($election))->toBe([]);
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 12)', function () {
    [$t, $election] = createSignedIn($this);

    $this->browser->rawBody('POST', createUrl($election), '{"title": "X"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 13)', function () {
    [$t, $election] = createSignedIn($this);
    // The 120 writes below are creations, so the 50 ballot limit would answer 409 first: use invalid
    // bodies, which count against the limiter the same way.
    foreach (range(1, 120) as $i) {
        $this->browser->post(createUrl($election), ['title' => ''])->assertStatus(422);
    }

    $response = $this->browser->post(createUrl($election), ['title' => 'Trop']);

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(Accounts::ballotRows($election))->toBe([]);
});

it('answers 405 for another method than GET, HEAD, POST [FR-BAL-01] (scenario 14)', function (string $method) {
    [$t, $election] = createSignedIn($this);

    $this->browser->other($method, createUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['PATCH', 'DELETE']);

it('ignores fields a request must not set: position, institution, election, id [FR-INST-05] (notes)', function () {
    [$t, $election] = createSignedIn($this);

    $response = $this->browser->post(createUrl($election), [
        'title' => 'Seul', 'position' => 9, 'id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
        'institution' => $t['b']['owner']['institution'], 'institution_id' => 2, 'election_id' => 1, 'scope' => 'group',
    ])->assertCreated();

    $row = Accounts::ballotRow($response->json('data.id'));
    expect($response->json('data.position'))->toBe(1)
        ->and($response->json('data.id'))->not->toBe('7c9e6679-7425-40de-944b-e07fc1f90ae7')
        ->and($row['scope'])->toBe('general')
        ->and($row['institution_id'])->toBe(Accounts::electionRow($election)['institution_id']);
});
