<?php

/*
 * POST /api/v1/elections/{election}/parties — docs/api/parties/POST-elections-{election}-parties.md
 * Tenant suite, route api/v1/elections/{election}/parties: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const PARTY_CREATE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function partyCreateUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/parties";
}

function partyCreateSignedIn($test, array $options = []): array
{
    $t = Team::two();
    $election = Accounts::plantElection($options + ['institution' => $t['a']['owner']['institution']]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('creates a party from a name and a colour [FR-CAND-01] (scenario 1)', function () {
    [$t, $election] = partyCreateSignedIn($this);

    $response = $this->browser->post(partyCreateUrl($election), ['name' => '  Avenir Étudiant  ', 'colour' => '#5468d4'])->assertCreated();

    $row = Accounts::partyRow($response->json('data.id'));
    expect($response->json('data'))->toMatchArray(['name' => 'Avenir Étudiant', 'acronym' => null, 'colour' => '#5468D4', 'logo' => null, 'candidates_count' => 0])
        ->and($response->headers->get('Location'))->toBe('/api/v1/parties/'.$response->json('data.id'))
        ->and($row['institution_id'])->toBe(Accounts::electionRow($election)['institution_id'])
        ->and($row['election_id'])->toBe(Accounts::electionRow($election)['id']);
});

it('takes an acronym and lets a manager create too [FR-CAND-01] (scenario 2)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->post(partyCreateUrl($election), ['name' => 'Ensemble', 'acronym' => ' EN ', 'colour' => '#C2410C'])->assertCreated()->assertJsonPath('data.acronym', 'EN');
});

it('answers 422 when the name is missing, blank or too long [FR-CAND-01] (scenario 3)', function (array $body, string $rule) {
    [$t, $election] = partyCreateSignedIn($this);

    $response = $this->browser->post(partyCreateUrl($election), $body + ['colour' => '#5468D4']);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.name'))->toContain($rule)
        ->and(Accounts::partyRows($election))->toBe([]);
})->with([
    'missing' => [[], 'required'],
    'blank' => [['name' => '   '], 'required'],
    'too long' => [['name' => str_repeat('é', 101)], 'max'],
]);

it('answers 422 for a name already used in the election, ignoring case and spaces [FR-CAND-01] (scenario 4)', function () {
    [$t, $election] = partyCreateSignedIn($this);
    Accounts::plantParty(['election' => $election, 'name' => 'Ensemble']);
    $other = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $response = $this->browser->post(partyCreateUrl($election), ['name' => '  ENSEMBLE ', 'colour' => '#5468D4']);

    $response->assertStatus(422);
    expect($response->json('error.fields.name'))->toContain('unique')
        ->and(Accounts::partyRows($election))->toHaveCount(1);
    // The same name in another election is fine.
    $this->browser->post(partyCreateUrl($other), ['name' => 'Ensemble', 'colour' => '#5468D4'])->assertCreated();
});

it('answers 422 for an acronym over 15 and for a colour that is not #RRGGBB [FR-CAND-01] (scenarios 5, 6)', function (array $body, string $field, string $rule) {
    [$t, $election] = partyCreateSignedIn($this);

    $response = $this->browser->post(partyCreateUrl($election), $body + ['name' => 'X', 'colour' => '#5468D4']);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json("error.fields.{$field}"))->toContain($rule)
        ->and(Accounts::partyRows($election))->toBe([]);
})->with([
    'acronym too long' => [['acronym' => str_repeat('A', 16)], 'acronym', 'max'],
    'colour missing' => [['colour' => null], 'colour', 'required'],
    'colour name' => [['colour' => 'blue'], 'colour', 'hex_colour'],
    'colour short' => [['colour' => '#FFF'], 'colour', 'hex_colour'],
    'colour no hash' => [['colour' => '5468D4'], 'colour', 'hex_colour'],
]);

it('answers 409 party_limit_reached at 30 parties [FR-CAND-01] (scenario 7)', function () {
    [$t, $election] = partyCreateSignedIn($this);
    foreach (range(1, 30) as $i) {
        Accounts::plantParty(['election' => $election, 'name' => "Parti {$i}"]);
    }

    $this->browser->post(partyCreateUrl($election), ['name' => 'Le 31e', 'colour' => '#5468D4'])
        ->assertStatus(409)->assertJsonPath('error.code', 'party_limit_reached');
    expect(Accounts::partyRows($election))->toHaveCount(30);
});

it('answers 409 for an election that is not a draft, and adds nothing [FR-SEC-06] (scenario 8)', function (string $status) {
    [$t, $election] = partyCreateSignedIn($this, ['status' => $status]);

    $this->browser->post(partyCreateUrl($election), ['name' => 'Trop tard', 'colour' => '#5468D4'])
        ->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(Accounts::partyRows($election))->toBe([]);
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('checks the body before the state [FR-SEC-06] (scenario 8)', function () {
    [$t, $election] = partyCreateSignedIn($this, ['status' => 'open']);

    $this->browser->post(partyCreateUrl($election), ['name' => ''])->assertStatus(422);
});

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 9)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);
    $body = ['name' => 'X', 'colour' => '#5468D4'];

    $unknown = Team::shape($this->browser->post(partyCreateUrl(PARTY_CREATE_UNKNOWN), $body));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(partyCreateUrl($target), $body)))->toBe($unknown);
    }
    expect(Accounts::partyRows($foreign))->toBe([]);
});

it('answers 401 when nobody is signed in [FR-CAND-01] (scenario 10)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $this->browser->post(partyCreateUrl($election), ['name' => 'X', 'colour' => '#5468D4'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::partyRows($election))->toBe([]);
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 11)', function () {
    [$t, $election] = partyCreateSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->post(partyCreateUrl($election), ['name' => 'X', 'colour' => '#5468D4'])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::partyRows($election))->toBe([]);
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 12)', function () {
    [$t, $election] = partyCreateSignedIn($this);

    $this->browser->post(partyCreateUrl($election), ['name' => 'X', 'colour' => '#5468D4'], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 13)', function () {
    [$t, $election] = partyCreateSignedIn($this);

    $this->browser->rawBody('POST', partyCreateUrl($election), '{"name": "X"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 14)', function () {
    [$t, $election] = partyCreateSignedIn($this);
    foreach (range(1, 120) as $i) {
        $this->browser->post(partyCreateUrl($election), ['name' => ''])->assertStatus(422);
    }

    $response = $this->browser->post(partyCreateUrl($election), ['name' => 'Trop', 'colour' => '#5468D4']);

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(Accounts::partyRows($election))->toBe([]);
});

it('answers 405 for another method than GET, HEAD, POST [FR-CAND-01] (scenario 15)', function (string $method) {
    [$t, $election] = partyCreateSignedIn($this);

    $this->browser->other($method, partyCreateUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['PATCH', 'DELETE']);

it('ignores fields a request must not set: id, institution, election, logo [FR-INST-05] (notes)', function () {
    [$t, $election] = partyCreateSignedIn($this);

    $response = $this->browser->post(partyCreateUrl($election), [
        'name' => 'Seul', 'colour' => '#5468D4', 'id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
        'institution' => $t['b']['owner']['institution'], 'institution_id' => 2, 'election_id' => 1,
        'logo' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'logo_file' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
    ])->assertCreated();

    $row = Accounts::partyRow($response->json('data.id'));
    expect($response->json('data.id'))->not->toBe('7c9e6679-7425-40de-944b-e07fc1f90ae7')
        ->and($response->json('data.logo'))->toBeNull()
        ->and($row['logo_file'])->toBeNull()
        ->and($row['institution_id'])->toBe(Accounts::electionRow($election)['institution_id']);
});
