<?php

/*
 * POST /api/v1/auth/two-factor/disable — docs/api/auth/POST-auth-two-factor-disable.md
 * Route in the tenant suite: api/v1/auth/two-factor/disable (the user's own, no record named).
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Totp;
use Tests\Support\TwoFactor;

const TF_DISABLE = '/api/v1/auth/two-factor/disable';

/** A user with two-factor on, signed in in $this->browser. Returns [user, two]. */
function disableSignedIn($test): array
{
    $user = Accounts::user();
    $two = TwoFactor::enable($test, $user);
    TwoFactor::pending($test, $user, $test->browser);
    $test->browser->post('/api/v1/auth/two-factor-challenge', ['code' => Totp::code($two['secret'])])->assertOk();

    return [$user, $two];
}

it('turns two-factor off and clears everything stored [FR-INST-04] (scenario 1)', function () {
    [$user, $two] = disableSignedIn($this);

    $this->browser->post(TF_DISABLE, ['password' => $user['password']])->assertNoContent();

    $row = TwoFactor::row($user['user']);
    expect($row['two_factor_secret'])->toBeNull()
        ->and($row['two_factor_recovery_codes'])->toBeNull()
        ->and($row['two_factor_confirmed_at'])->toBeNull()
        ->and($row['two_factor_last_step'])->toBeNull();
    $this->browser->get('/api/v1/auth/two-factor')->assertOk()->assertExactJson(['data' => ['enabled' => false, 'setup_started' => false, 'recovery_codes_left' => null]]);

    // The next sign-in asks for the password only.
    (new AuthClient($this))->login($user['email'], $user['password'])->assertOk()->assertJsonPath('data.id', $user['user']);
});

it('answers 422 when the password is missing [FR-INST-04] (scenario 2)', function () {
    [$user, $two] = disableSignedIn($this);

    $response = $this->browser->post(TF_DISABLE, []);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.password'))->toContain('required')
        ->and(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->not->toBeNull();
});

it('answers 422, not 401, for a wrong password: a stolen open session cannot turn it off [NFR-SEC-01] (scenario 3)', function () {
    [$user, $two] = disableSignedIn($this);

    $response = $this->browser->post(TF_DISABLE, ['password' => 'not the password at all']);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.password'))->toContain('incorrect')
        ->and(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->not->toBeNull();
    $this->browser->get('/api/v1/auth/me')->assertOk();
});

it('answers 409 when two-factor is not turned on, an unconfirmed setup included [FR-INST-04] (scenario 4)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();
    $this->browser->post(TF_DISABLE, ['password' => $user['password']])->assertStatus(409)->assertJsonPath('error.code', 'two_factor_not_enabled');

    TwoFactor::plantUnconfirmed($user['user'], 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');
    $this->browser->post(TF_DISABLE, ['password' => $user['password']])->assertStatus(409)->assertJsonPath('error.code', 'two_factor_not_enabled');
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-04] (scenario 5)', function () {
    $this->browser->post(TF_DISABLE, ['password' => 'whatever whatever'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 6)', function () {
    [$user, $two] = disableSignedIn($this);
    Accounts::suspend($user['institution']);

    $this->browser->post(TF_DISABLE, ['password' => $user['password']])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->not->toBeNull();
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 7)', function () {
    [$user, $two] = disableSignedIn($this);

    $this->browser->post(TF_DISABLE, ['password' => $user['password']], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->not->toBeNull();
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 8)', function () {
    disableSignedIn($this);

    $this->browser->postRaw(TF_DISABLE, '{"password": "x"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 10 requests a minute from one user [NFR-SEC-05] (scenario 9)', function () {
    [$user, $two] = disableSignedIn($this);

    // Turning two-factor on took two of the ten requests of the minute (setup and confirm).
    foreach (range(1, 8) as $i) {
        $this->browser->post(TF_DISABLE, ['password' => 'not the password at all'])->assertStatus(422);
    }

    $this->browser->post(TF_DISABLE, ['password' => $user['password']])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->not->toBeNull();
});

it('answers 405 to another method than POST [NFR-SEC-01] (scenario 10)', function (string $method) {
    disableSignedIn($this);

    $this->browser->other($method, TF_DISABLE)->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['GET', 'PUT', 'PATCH', 'DELETE']);
