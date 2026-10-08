<?php

/*
 * GET /api/v1/auth/two-factor — docs/api/auth/GET-auth-two-factor.md
 * Route in the tenant suite: api/v1/auth/two-factor (the user's own, no record named).
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Totp;
use Tests\Support\TwoFactor;

const TF_STATUS = '/api/v1/auth/two-factor';

it('says two-factor is not turned on [FR-INST-04] (scenario 1)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $response = $this->browser->get(TF_STATUS)->assertOk();

    expect($response->json())->toBe(['data' => ['enabled' => false, 'setup_started' => false, 'recovery_codes_left' => null]]);
});

it('says a setup was started and not confirmed [FR-INST-04] (scenario 2)', function () {
    $user = Accounts::user();
    TwoFactor::plantUnconfirmed($user['user'], 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $this->browser->get(TF_STATUS)->assertOk()->assertExactJson(['data' => ['enabled' => false, 'setup_started' => true, 'recovery_codes_left' => null]]);
});

it('says two-factor is turned on and how many recovery codes are left [FR-INST-04] (scenario 3)', function () {
    $user = Accounts::user();
    $two = TwoFactor::enable($this, $user);
    $pending = TwoFactor::pending($this, $user, $this->browser);

    $this->browser->post('/api/v1/auth/two-factor-challenge', ['code' => Totp::code($two['secret'])])->assertOk();

    $response = $this->browser->get(TF_STATUS)->assertOk();
    expect($response->json())->toBe(['data' => ['enabled' => true, 'setup_started' => false, 'recovery_codes_left' => 8]]);

    // Neither the secret nor a code is ever in the answer.
    expect($response->getContent())->not->toContain($two['secret'])->not->toContain($two['codes'][0]);
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-04] (scenario 4)', function () {
    $this->browser->get(TF_STATUS)->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 5)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();
    Accounts::suspend($user['institution']);

    $this->browser->get(TF_STATUS)->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 405 to another method than GET or HEAD [NFR-SEC-01] (scenario 6)', function (string $method) {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $this->browser->other($method, TF_STATUS)->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('is the answer for the user of the session only [FR-INST-05]', function () {
    $a = Accounts::user();
    $b = Accounts::user();
    TwoFactor::enable($this, $b);
    $this->browser->login($a['email'], $a['password'])->assertOk();

    $this->browser->get(TF_STATUS.'?user='.$b['user'])->assertOk()->assertJsonPath('data.enabled', false);
});
