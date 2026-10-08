<?php

/*
 * PATCH /api/v1/institution — docs/api/institution/PATCH-institution.md
 * Route in the tenant suite: api/v1/institution (the tenant itself, no record named).
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const PATCH_INSTITUTION = '/api/v1/institution';

function signedInOwner($test): array
{
    $owner = Accounts::user();
    Team::signIn($test, $owner);

    return $owner;
}

function storedInstitution(array $account): array
{
    return Accounts::institutionRow($account['institution']);
}

it('changes the fields that are sent and only those [FR-INST-02] (scenario 1)', function () {
    $owner = signedInOwner($this);
    Accounts::updateInstitution($owner['institution'], ['city' => 'Jacmel', 'description' => 'Avant']);
    $before = storedInstitution($owner);

    $response = $this->browser->patch(PATCH_INSTITUTION, [
        'name' => '  Collège Étoile du Matin  ',
        'type' => 'school',
        'address' => '12 rue des Palmistes',
        'phone' => '+509 (22) 22-00.00',
        'contact_email' => 'direction@etoile.example',
        'timezone' => 'America/New_York',
        'language' => 'en',
    ])->assertOk();

    $after = storedInstitution($owner);

    expect($response->json('data'))->toMatchArray([
        'id' => $owner['institution'],
        'name' => 'Collège Étoile du Matin',
        'type' => 'school',
        'address' => '12 rue des Palmistes',
        'phone' => '+509 (22) 22-00.00',
        'contact_email' => 'direction@etoile.example',
        'timezone' => 'America/New_York',
        'language' => 'en',
        'city' => 'Jacmel',
        'description' => 'Avant',
    ])
        ->and($after['name'])->toBe('Collège Étoile du Matin')
        ->and($after['city'])->toBe('Jacmel')
        ->and($after['id'])->toBe($before['id']);

    $this->browser->get(PATCH_INSTITUTION)->assertOk()->assertJsonPath('data.name', 'Collège Étoile du Matin');
});

it('answers 200 and changes nothing for an empty body or no known field [FR-INST-02] (scenario 2)', function (array $body) {
    $owner = signedInOwner($this);
    $before = storedInstitution($owner);

    $this->browser->patch(PATCH_INSTITUTION, $body)->assertOk()->assertJsonPath('data.name', $before['name']);

    $after = storedInstitution($owner);
    unset($before['updated_at'], $after['updated_at']);
    expect($after)->toBe($before);
})->with([[[]], [['colour' => 'blue']]]);

it('stores an optional field sent as an empty string as null [FR-INST-02] (scenario 3)', function () {
    $owner = signedInOwner($this);
    Accounts::updateInstitution($owner['institution'], ['description' => 'x', 'address' => 'x', 'city' => 'x', 'phone' => '1', 'contact_email' => 'a@example.test']);

    $response = $this->browser->patch(PATCH_INSTITUTION, ['description' => '', 'address' => '   ', 'city' => '', 'phone' => '', 'contact_email' => ''])->assertOk();

    foreach (['description', 'address', 'city', 'phone', 'contact_email'] as $field) {
        expect($response->json("data.{$field}"))->toBeNull()
            ->and(storedInstitution($owner)[$field])->toBeNull();
    }
});

it('answers 422 when the name is empty, blank or null [FR-INST-02] (scenario 4)', function (mixed $value) {
    $owner = signedInOwner($this);
    $before = storedInstitution($owner)['name'];

    $response = $this->browser->patch(PATCH_INSTITUTION, ['name' => $value]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.name'))->toContain('required')
        ->and(storedInstitution($owner)['name'])->toBe($before);
})->with([[''], ['   '], [null]]);

it('answers 422 when a text is too long, and accepts the limit itself [FR-INST-02] (scenarios 5 and 7)', function (string $field, int $max) {
    $owner = signedInOwner($this);

    $response = $this->browser->patch(PATCH_INSTITUTION, [$field => str_repeat('é', $max + 1)]);
    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json("error.fields.{$field}"))->toContain('max');

    // Counted in characters, not bytes.
    $this->browser->patch(PATCH_INSTITUTION, [$field => str_repeat('é', $max)])->assertOk();
})->with([['name', 150], ['description', 500], ['address', 255], ['city', 100]]);

it('answers 422 when the phone is too long or holds a letter or another symbol [FR-INST-02] (scenarios 7 and 8)', function (string $phone, string $rule) {
    $owner = signedInOwner($this);

    $response = $this->browser->patch(PATCH_INSTITUTION, ['phone' => $phone]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.phone'))->toContain($rule)
        ->and(storedInstitution($owner)['phone'])->toBeNull();
})->with([[str_repeat('1', 31), 'max'], ['509 22 22 abc', 'format'], ['509#2222', 'format'], ['<script>', 'format']]);

it('answers 422 when the type is not one of the four [FR-INST-02] (scenario 6)', function (mixed $value) {
    $owner = signedInOwner($this);
    $before = storedInstitution($owner)['type'];

    $response = $this->browser->patch(PATCH_INSTITUTION, ['type' => $value]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.type'))->toContain('in')
        ->and(storedInstitution($owner)['type'])->toBe($before);
})->with([['company'], ['School'], [''], [3]]);

it('accepts each of the four types [FR-INST-02] (scenario 1)', function (string $type) {
    $owner = signedInOwner($this);

    $this->browser->patch(PATCH_INSTITUTION, ['type' => $type])->assertOk()->assertJsonPath('data.type', $type);
})->with(['school', 'university', 'association', 'other']);

it('answers 422 when the contact email is not an address [FR-INST-02] (scenario 9)', function (string $value, string $rule) {
    $owner = signedInOwner($this);

    $response = $this->browser->patch(PATCH_INSTITUTION, ['contact_email' => $value]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.contact_email'))->toContain($rule)
        ->and(storedInstitution($owner)['contact_email'])->toBeNull();
})->with([['not an address', 'email'], ['a@', 'email'], ['x@'.str_repeat(str_repeat('d', 60).'.', 5).'test', 'max']]);

it('answers 422 when the time zone is not a known identifier [FR-INST-02] (scenario 10)', function (string $value) {
    $owner = signedInOwner($this);

    $response = $this->browser->patch(PATCH_INSTITUTION, ['timezone' => $value]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.timezone'))->toContain('timezone')
        ->and(storedInstitution($owner)['timezone'])->toBe('America/Port-au-Prince');
})->with([['Mars/Olympus'], ['Haiti'], ['UTC+5']]);

it('answers 422 when the language is not fr or en [FR-INST-02] (scenario 11)', function (mixed $value) {
    $owner = signedInOwner($this);

    $response = $this->browser->patch(PATCH_INSTITUTION, ['language' => $value]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.language'))->toContain('in')
        ->and(storedInstitution($owner)['language'])->toBe('fr');
})->with([['es'], ['FR'], [''], [1]]);

it('answers 403 to a manager and changes nothing [FR-INST-03] (scenario 12)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['manager']);
    $before = Accounts::institutionRow($t['a']['owner']['institution']);

    $response = $this->browser->patch(PATCH_INSTITUTION, ['name' => 'Pris en otage']);

    $response->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    expect(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message']);

    $after = Accounts::institutionRow($t['a']['owner']['institution']);
    expect($after['name'])->toBe($before['name']);
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-02] (scenario 13)', function () {
    $this->browser->patch(PATCH_INSTITUTION, ['name' => 'X'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 and changes nothing when the institution was suspended since sign-in [FR-INST-06] (scenario 14)', function () {
    $owner = signedInOwner($this);
    $before = storedInstitution($owner)['name'];
    Accounts::suspend($owner['institution']);

    $this->browser->patch(PATCH_INSTITUTION, ['name' => 'Autre nom'])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(storedInstitution($owner)['name'])->toBe($before);
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 15)', function () {
    $owner = signedInOwner($this);

    $this->browser->patch(PATCH_INSTITUTION, ['name' => 'X'], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->patch(PATCH_INSTITUTION, ['name' => 'X'], ['X-XSRF-TOKEN' => 'not-the-token'], false)->assertStatus(419);
    expect(storedInstitution($owner)['name'])->not->toBe('X');
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 16)', function () {
    signedInOwner($this);

    $this->browser->rawBody('PATCH', PATCH_INSTITUTION, '{"name": "X"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 405 to another method than GET, HEAD or PATCH [NFR-SEC-01] (scenario 17)', function (string $method) {
    signedInOwner($this);

    $this->browser->other($method, PATCH_INSTITUTION)->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['POST', 'PUT', 'DELETE']);

it('ignores what it must not change: suspension, logo, id and the other institution [FR-INST-05] (notes)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);
    $otherBefore = Accounts::institutionRow($t['b']['owner']['institution']);
    $before = storedInstitution($t['a']['owner']);

    $this->browser->patch(PATCH_INSTITUTION, [
        'name' => 'Nouveau nom',
        'suspended_at' => '2026-01-01 00:00:00',
        'logo_file' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
        'uuid' => $t['b']['owner']['institution'],
        'id' => $t['b']['owner']['institution'],
        'institution' => $t['b']['owner']['institution'],
        'institution_id' => 1,
    ])->assertOk()->assertJsonPath('data.id', $t['a']['owner']['institution']);

    $after = storedInstitution($t['a']['owner']);
    expect($after['name'])->toBe('Nouveau nom')
        ->and($after['suspended_at'])->toBeNull()
        ->and($after['logo_file'])->toBeNull()
        ->and($after['uuid'])->toBe($before['uuid'])
        ->and(Accounts::institutionRow($t['b']['owner']['institution']))->toBe($otherBefore);
});
