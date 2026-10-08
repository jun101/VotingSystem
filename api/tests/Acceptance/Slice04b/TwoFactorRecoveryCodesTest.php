<?php

/*
 * POST /api/v1/auth/two-factor/recovery-codes — docs/api/auth/POST-auth-two-factor-recovery-codes.md
 * Route in the tenant suite: api/v1/auth/two-factor/recovery-codes (the user's own, no record named).
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Totp;
use Tests\Support\TwoFactor;

const TF_CODES = '/api/v1/auth/two-factor/recovery-codes';

function codesSignedIn($test): array
{
    $user = Accounts::user();
    $two = TwoFactor::enable($test, $user);
    TwoFactor::pending($test, $user, $test->browser);
    $test->browser->post('/api/v1/auth/two-factor-challenge', ['code' => Totp::code($two['secret'])])->assertOk();

    return [$user, $two];
}

it('replaces the recovery codes with eight new ones, the old ones stop working [FR-INST-04] (scenario 1)', function () {
    [$user, $two] = codesSignedIn($this);

    $response = $this->browser->post(TF_CODES, ['password' => $user['password']])->assertOk();

    $codes = $response->json('data.recovery_codes');
    expect(array_keys($response->json()))->toBe(['data'])
        ->and($codes)->toHaveCount(8)
        ->and(array_unique($codes))->toHaveCount(8)
        ->and(array_intersect($codes, $two['codes']))->toBe([]);
    foreach ($codes as $code) {
        expect($code)->toMatch('/^[a-z0-9]{5}-[a-z0-9]{5}$/');
    }
    expect(TwoFactor::storedRecoveryHashes($user['user']))->toHaveCount(8);

    // An old code is refused, a new one works.
    $old = TwoFactor::pending($this, $user, new AuthClient($this));
    $old->post('/api/v1/auth/two-factor-challenge', ['recovery_code' => $two['codes'][0]])->assertStatus(422);
    $old->post('/api/v1/auth/two-factor-challenge', ['recovery_code' => $codes[0]])->assertOk();
});

it('counts eight again after renewing, whatever had been used [FR-INST-04] (scenario 1)', function () {
    [$user, $two] = codesSignedIn($this);
    $other = TwoFactor::pending($this, $user, new AuthClient($this));
    $other->post('/api/v1/auth/two-factor-challenge', ['recovery_code' => $two['codes'][0]])->assertOk();
    $this->browser->get('/api/v1/auth/two-factor')->assertJsonPath('data.recovery_codes_left', 7);

    $this->browser->post(TF_CODES, ['password' => $user['password']])->assertOk();

    $this->browser->get('/api/v1/auth/two-factor')->assertJsonPath('data.recovery_codes_left', 8);
});

it('answers 422 when the password is missing [FR-INST-04] (scenario 2)', function () {
    [$user, $two] = codesSignedIn($this);

    $response = $this->browser->post(TF_CODES, []);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.password'))->toContain('required');
});

it('answers 422 for a wrong password and keeps the old codes [NFR-SEC-01] (scenario 3)', function () {
    [$user, $two] = codesSignedIn($this);
    $before = TwoFactor::storedRecoveryHashes($user['user']);

    $response = $this->browser->post(TF_CODES, ['password' => 'not the password at all']);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.password'))->toContain('incorrect')
        ->and(TwoFactor::storedRecoveryHashes($user['user']))->toBe($before);
});

it('answers 409 when two-factor is not turned on [FR-INST-04] (scenario 4)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $this->browser->post(TF_CODES, ['password' => $user['password']])->assertStatus(409)->assertJsonPath('error.code', 'two_factor_not_enabled');
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-04] (scenario 5)', function () {
    $this->browser->post(TF_CODES, ['password' => 'whatever whatever'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 6)', function () {
    [$user, $two] = codesSignedIn($this);
    Accounts::suspend($user['institution']);

    $this->browser->post(TF_CODES, ['password' => $user['password']])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 7)', function () {
    [$user, $two] = codesSignedIn($this);

    $this->browser->post(TF_CODES, ['password' => $user['password']], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 8)', function () {
    codesSignedIn($this);

    $this->browser->postRaw(TF_CODES, '{"password": "x"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 10 requests a minute from one user [NFR-SEC-05] (scenario 9)', function () {
    [$user, $two] = codesSignedIn($this);

    // Turning two-factor on took two of the ten requests of the minute (setup and confirm).
    foreach (range(1, 8) as $i) {
        $this->browser->post(TF_CODES, ['password' => $user['password']])->assertOk();
    }

    $this->browser->post(TF_CODES, ['password' => $user['password']])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});

it('ends the session at the 5th wrong password in 15 minutes and keeps the codes [NFR-SEC-01, NFR-SEC-05] (scenario 3b)', function () {
    [$user, $two] = codesSignedIn($this);
    $before = TwoFactor::storedRecoveryHashes($user['user']);

    foreach (range(1, 4) as $i) {
        $this->browser->post(TF_CODES, ['password' => 'not the password at all'])->assertStatus(422);
    }

    $this->browser->post(TF_CODES, ['password' => 'not the password at all'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
    expect(TwoFactor::storedRecoveryHashes($user['user']))->toBe($before);
});

it('answers 405 to another method than POST [NFR-SEC-01] (scenario 10)', function (string $method) {
    codesSignedIn($this);

    $this->browser->other($method, TF_CODES)->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['GET', 'PUT', 'PATCH', 'DELETE']);
