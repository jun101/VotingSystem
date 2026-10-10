<?php

/*
 * PATCH /api/v1/parties/{party} — docs/api/parties/PATCH-parties-{party}.md
 * Tenant suite, route api/v1/parties/{party}: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const PARTY_PATCH_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

/** An owner of A signed in, a draft (or the given status) and one party in it. Returns [team, election, party]. */
function partyPatchSignedIn($test, string $status = 'draft', array $party = []): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $id = Accounts::plantParty($party + ['election' => $election, 'name' => 'Avant', 'acronym' => 'AV', 'colour' => '#5468D4']);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $id];
}

function partyPatchUrl(string $uuid): string
{
    return "/api/v1/parties/{$uuid}";
}

it('changes the fields that are sent and only those [FR-CAND-01] (scenario 1)', function () {
    [$t, $election, $id] = partyPatchSignedIn($this);
    $before = Accounts::partyRow($id);

    $response = $this->browser->patch(partyPatchUrl($id), ['name' => '  Après  ', 'colour' => '#c2410c'])->assertOk();

    $after = Accounts::partyRow($id);
    expect($response->json('data'))->toMatchArray(['id' => $id, 'name' => 'Après', 'acronym' => 'AV', 'colour' => '#C2410C'])
        ->and($after['name_key'])->toBe('après')
        ->and($after['id'])->toBe($before['id'])
        ->and($after['election_id'])->toBe($before['election_id'])
        ->and($after['created_at'])->toBe($before['created_at']);
});

it('lets a manager change a party too [FR-CAND-01] (scenario 1)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $id = Accounts::plantParty(['election' => $election]);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->patch(partyPatchUrl($id), ['name' => 'Par le gestionnaire'])->assertOk();
});

it('answers 200 and changes nothing for an empty body or no known field [FR-CAND-01] (scenario 2)', function (array $body) {
    [$t, $election, $id] = partyPatchSignedIn($this);
    $before = Accounts::partyRow($id);

    $this->browser->patch(partyPatchUrl($id), $body)->assertOk()->assertJsonPath('data.name', 'Avant');

    $after = Accounts::partyRow($id);
    unset($before['updated_at'], $after['updated_at']);
    expect($after)->toBe($before);
})->with([[[]], [['slogan' => 'x']]]);

it('stores an acronym sent as an empty string or null as null [FR-CAND-01] (scenario 3)', function (mixed $value) {
    [$t, $election, $id] = partyPatchSignedIn($this);

    $this->browser->patch(partyPatchUrl($id), ['acronym' => $value])->assertOk()->assertJsonPath('data.acronym', null);
    expect(Accounts::partyRow($id)['acronym'])->toBeNull();
})->with(['', '   ', null]);

it('answers 422 when the name is blank, null or too long [FR-CAND-01] (scenario 4)', function (mixed $value, string $rule) {
    [$t, $election, $id] = partyPatchSignedIn($this);

    $response = $this->browser->patch(partyPatchUrl($id), ['name' => $value]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.name'))->toContain($rule)
        ->and(Accounts::partyRow($id)['name'])->toBe('Avant');
})->with([[' ', 'required'], [null, 'required'], [str_repeat('é', 101), 'max']]);

it('answers 422 for a name used by another party, and accepts its own name [FR-CAND-01] (scenario 5)', function () {
    [$t, $election, $id] = partyPatchSignedIn($this);
    Accounts::plantParty(['election' => $election, 'name' => 'Ensemble']);

    $response = $this->browser->patch(partyPatchUrl($id), ['name' => 'ensemble']);

    $response->assertStatus(422);
    expect($response->json('error.fields.name'))->toContain('unique');
    $this->browser->patch(partyPatchUrl($id), ['name' => ' AVANT ', 'colour' => '#0F766E'])->assertOk();
});

it('answers 422 for a bad colour or acronym [FR-CAND-01] (scenario 6)', function (array $body, string $field, string $rule) {
    [$t, $election, $id] = partyPatchSignedIn($this);

    $response = $this->browser->patch(partyPatchUrl($id), $body);

    $response->assertStatus(422);
    expect($response->json("error.fields.{$field}"))->toContain($rule)
        ->and(Accounts::partyRow($id)['colour'])->toBe('#5468D4');
})->with([
    'colour' => [['colour' => 'red'], 'colour', 'hex_colour'],
    'acronym' => [['acronym' => str_repeat('A', 16)], 'acronym', 'max'],
]);

it('answers 409 for an election that is not a draft, and changes nothing [FR-SEC-06] (scenario 7)', function (string $status) {
    [$t, $election, $id] = partyPatchSignedIn($this, $status);

    $this->browser->patch(partyPatchUrl($id), ['name' => 'Après'])->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(Accounts::partyRow($id)['name'])->toBe('Avant');
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for a party that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 8)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantParty(['election' => $foreignElection, 'name' => 'De B']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->patch(partyPatchUrl(PARTY_PATCH_UNKNOWN), ['name' => 'X']));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->patch(partyPatchUrl($target), ['name' => 'X'])))->toBe($unknown);
    }
    expect(Team::shape($this->browser->patch(partyPatchUrl($foreign), ['name' => ''])))->toBe($unknown)
        ->and(Accounts::partyRow($foreign)['name'])->toBe('De B');
});

it('answers 401 when nobody is signed in [FR-CAND-01] (scenario 9)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $id = Accounts::plantParty(['election' => $election, 'name' => 'Avant']);

    $this->browser->patch(partyPatchUrl($id), ['name' => 'Après'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::partyRow($id)['name'])->toBe('Avant');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 10)', function () {
    [$t, $election, $id] = partyPatchSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->patch(partyPatchUrl($id), ['name' => 'Après'])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 11)', function () {
    [$t, $election, $id] = partyPatchSignedIn($this);

    $this->browser->patch(partyPatchUrl($id), ['name' => 'Après'], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 12)', function () {
    [$t, $election, $id] = partyPatchSignedIn($this);

    $this->browser->rawBody('PATCH', partyPatchUrl($id), '{"name": "X"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 13)', function () {
    [$t, $election, $id] = partyPatchSignedIn($this);

    foreach (range(1, 120) as $i) {
        $this->browser->patch(partyPatchUrl($id), ['name' => "T{$i}"])->assertOk();
    }

    $this->browser->patch(partyPatchUrl($id), ['name' => 'Trop'])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect(Accounts::partyRow($id)['name'])->toBe('T120');
});

it('answers 405 for another method than PATCH and DELETE [FR-CAND-01] (scenario 14)', function (string $method) {
    [$t, $election, $id] = partyPatchSignedIn($this);

    $this->browser->other($method, partyPatchUrl($id))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['GET', 'POST', 'PUT']);

it('ignores id, election, logo and candidates_count in the body [FR-INST-05] (notes)', function () {
    [$t, $election, $id] = partyPatchSignedIn($this);
    $before = Accounts::partyRow($id);

    $this->browser->patch(partyPatchUrl($id), [
        'name' => 'Après', 'id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'election_id' => 99,
        'logo' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'candidates_count' => 5,
    ])->assertOk();

    $after = Accounts::partyRow($id);
    expect($after['name'])->toBe('Après')
        ->and($after['uuid'])->toBe($before['uuid'])
        ->and($after['election_id'])->toBe($before['election_id'])
        ->and($after['logo_file'])->toBeNull();
});
