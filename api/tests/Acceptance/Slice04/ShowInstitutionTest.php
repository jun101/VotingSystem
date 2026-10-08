<?php

/*
 * GET /api/v1/institution — docs/api/institution/GET-institution.md
 * Route in the tenant suite: api/v1/institution (the tenant itself, no record named).
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const SHOW_INSTITUTION = '/api/v1/institution';

it('shows the profile to an owner [FR-INST-02] (scenario 1)', function () {
    $owner = Accounts::user(['language' => 'fr']);
    Accounts::updateInstitution($owner['institution'], [
        'name' => 'Collège Étoile du Matin',
        'type' => 'school',
        'description' => 'École secondaire de Port-au-Prince.',
        'address' => '12 rue des Palmistes',
        'city' => 'Port-au-Prince',
        'phone' => '+509 2222 0000',
        'contact_email' => 'direction@etoile.example',
    ]);
    Team::signIn($this, $owner);

    $response = $this->browser->get(SHOW_INSTITUTION)->assertOk();

    expect(array_keys($response->json()))->toBe(['data'])
        ->and(array_keys($response->json('data')))->toEqualCanonicalizing(['id', 'name', 'type', 'description', 'address', 'city', 'phone', 'contact_email', 'timezone', 'language', 'logo'])
        ->and($response->json('data'))->toMatchArray([
            'id' => $owner['institution'],
            'name' => 'Collège Étoile du Matin',
            'type' => 'school',
            'description' => 'École secondaire de Port-au-Prince.',
            'address' => '12 rue des Palmistes',
            'city' => 'Port-au-Prince',
            'phone' => '+509 2222 0000',
            'contact_email' => 'direction@etoile.example',
            'timezone' => 'America/Port-au-Prince',
            'language' => 'fr',
            'logo' => null,
        ])
        ->and($response->json('data.id'))->toMatch(UUID_V4);

    // Nothing internal: no numeric key, no suspension date.
    expect($response->getContent())->not->toContain('suspended_at')->not->toContain('logo_file')->not->toContain('institution_id');
});

it('shows the logo as three addresses under /media [FR-INST-02] (scenario 1)', function () {
    $owner = Accounts::user();
    $logo = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
    Accounts::updateInstitution($owner['institution'], ['logo_file' => $logo]);
    Team::signIn($this, $owner);

    $response = $this->browser->get(SHOW_INSTITUTION)->assertOk();

    expect($response->json('data.logo'))->toBe([
        'sm' => "/media/{$logo}-64.webp",
        'md' => "/media/{$logo}-160.webp",
        'lg' => "/media/{$logo}-480.webp",
    ]);
});

it('shows the profile to a manager too [FR-INST-02] (scenario 2)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['manager']);

    $this->browser->get(SHOW_INSTITUTION)->assertOk()
        ->assertJsonPath('data.id', $t['a']['owner']['institution']);
});

it('answers about the institution of the session only, whatever the request says [FR-INST-05] (scenario 1)', function () {
    $t = Team::two();
    Team::signIn($this, $t['b']['owner']);

    $response = $this->browser->get(SHOW_INSTITUTION.'?institution='.$t['a']['owner']['institution'].'&id='.$t['a']['owner']['institution']);

    $response->assertOk()->assertJsonPath('data.id', $t['b']['owner']['institution']);
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-02] (scenario 3)', function () {
    $response = $this->browser->get(SHOW_INSTITUTION);

    $response->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message']);
});

it('answers 403 to a platform admin, who has no institution [FR-INST-02] (scenario 4)', function () {
    $admin = Accounts::user(['role' => 'platform_admin']);
    Team::signIn($this, $admin);

    $this->browser->get(SHOW_INSTITUTION)->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 5)', function () {
    $owner = Accounts::user();
    Team::signIn($this, $owner);
    Accounts::suspend($owner['institution']);

    $this->browser->get(SHOW_INSTITUTION)->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 405 to another method than GET, HEAD or PATCH [NFR-SEC-01] (scenario 6)', function (string $method) {
    $owner = Accounts::user();
    Team::signIn($this, $owner);

    $response = $this->browser->other($method, SHOW_INSTITUTION);

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $allow = array_map('trim', explode(',', (string) $response->headers->get('Allow')));
    expect($allow)->toContain('GET')->toContain('HEAD')->toContain('PATCH')->not->toContain($method);
})->with(['POST', 'PUT', 'DELETE']);
