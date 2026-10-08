<?php

/*
 * DELETE /api/v1/institution/logo — docs/api/institution/DELETE-institution-logo.md
 * Route in the tenant suite: api/v1/institution/logo (the tenant itself, no record named).
 */

use Tests\Support\Accounts;
use Tests\Support\Images;
use Tests\Support\Team;

const DELETE_LOGO = '/api/v1/institution/logo';

function deleteLogoSignedIn($test, string $who = 'owner'): array
{
    $t = Team::two();
    $account = $t['a'][$who];
    Team::signIn($test, $account);

    return $account;
}

it('removes the logo and its three files [FR-INST-02] (scenario 1)', function () {
    $owner = deleteLogoSignedIn($this);
    $this->browser->upload('PUT', DELETE_LOGO, ['file' => Images::upload(Images::png())])->assertOk();
    expect(Team::mediaFiles())->toHaveCount(3);

    $this->browser->delete(DELETE_LOGO)->assertNoContent();

    expect(Team::mediaFiles())->toBe([])
        ->and(Accounts::institutionRow($owner['institution'])['logo_file'])->toBeNull();
    $this->browser->get('/api/v1/institution')->assertOk()->assertJsonPath('data.logo', null);
});

it('answers 204 when there is no logo, and again a second time [FR-INST-02] (scenario 2)', function () {
    deleteLogoSignedIn($this);

    $this->browser->delete(DELETE_LOGO)->assertNoContent();
    $this->browser->delete(DELETE_LOGO)->assertNoContent();
});

it('answers 403 to a manager and keeps the logo [FR-INST-03] (scenario 3)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);
    $this->browser->upload('PUT', DELETE_LOGO, ['file' => Images::upload(Images::png())])->assertOk();

    $manager = new Tests\Support\AuthClient($this);
    $manager->login($t['a']['manager']['email'], $t['a']['manager']['password'])->assertOk();

    $manager->delete(DELETE_LOGO)->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    expect(Team::mediaFiles())->toHaveCount(3)
        ->and(Accounts::institutionRow($t['a']['owner']['institution'])['logo_file'])->not->toBeNull();
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-02] (scenario 4)', function () {
    $this->browser->delete(DELETE_LOGO)->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 5)', function () {
    $owner = deleteLogoSignedIn($this);
    Accounts::suspend($owner['institution']);

    $this->browser->delete(DELETE_LOGO)->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 6)', function () {
    deleteLogoSignedIn($this);

    $this->browser->delete(DELETE_LOGO, [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
});

it('answers 405 to another method than PUT or DELETE [NFR-SEC-01] (scenario 7)', function (string $method) {
    deleteLogoSignedIn($this);

    $this->browser->other($method, DELETE_LOGO)->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['GET', 'POST', 'PATCH']);
