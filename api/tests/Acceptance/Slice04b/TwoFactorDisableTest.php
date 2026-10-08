<?php

/*
 * POST /api/v1/auth/two-factor/disable — docs/api/auth/POST-auth-two-factor-disable.md
 * Route in the tenant suite: api/v1/auth/two-factor/disable (the user's own, no record named).
 * Turning it off needs the password AND a current second factor.
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

/** The body of a good request: the password and an unused recovery code. */
function disableBody(array $user, array $two, int $code = 0): array
{
    return ['password' => $user['password'], 'recovery_code' => $two['codes'][$code]];
}

it('turns two-factor off with the password and a recovery code, and clears everything stored [FR-INST-04] (scenario 1)', function () {
    [$user, $two] = disableSignedIn($this);

    $this->browser->post(TF_DISABLE, disableBody($user, $two))->assertNoContent();

    $row = TwoFactor::row($user['user']);
    expect($row['two_factor_secret'])->toBeNull()
        ->and($row['two_factor_recovery_codes'])->toBeNull()
        ->and($row['two_factor_confirmed_at'])->toBeNull()
        ->and($row['two_factor_last_step'])->toBeNull();
    $this->browser->get('/api/v1/auth/two-factor')->assertOk()->assertExactJson(['data' => ['enabled' => false, 'setup_started' => false, 'recovery_codes_left' => null]]);

    // The next sign-in asks for the password only.
    (new AuthClient($this))->login($user['email'], $user['password'])->assertOk()->assertJsonPath('data.id', $user['user']);
});

it('turns it off with the password and the code of the next period [FR-INST-04] (scenario 1)', function () {
    [$user, $two] = disableSignedIn($this);

    // The sign-in used the current period; the next one is accepted, once.
    $this->browser->post(TF_DISABLE, ['password' => $user['password'], 'code' => Totp::code($two['secret'], Totp::step() + 1)])->assertNoContent();

    expect(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->toBeNull();
});

it('answers 422 when neither a code nor a recovery code is sent: a guessed password is not enough [NFR-SEC-01] (scenario 2a)', function () {
    [$user, $two] = disableSignedIn($this);

    $response = $this->browser->post(TF_DISABLE, ['password' => $user['password']]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.code'))->toContain('required')
        ->and(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->not->toBeNull();
});

it('answers 422 when the password is missing [FR-INST-04] (scenario 2)', function () {
    [$user, $two] = disableSignedIn($this);

    $response = $this->browser->post(TF_DISABLE, ['recovery_code' => $two['codes'][0]]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.password'))->toContain('required')
        ->and(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->not->toBeNull();
});

it('answers 422, not 401, for a wrong password: a stolen open session cannot turn it off [NFR-SEC-01] (scenario 3)', function () {
    [$user, $two] = disableSignedIn($this);

    $response = $this->browser->post(TF_DISABLE, ['password' => 'not the password at all', 'recovery_code' => $two['codes'][0]]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.password'))->toContain('incorrect')
        ->and(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->not->toBeNull();
    $this->browser->get('/api/v1/auth/me')->assertOk();
});

it('answers 422 for a wrong, used or malformed second factor, even with the right password [NFR-SEC-01] (scenario 3c)', function (string $kind) {
    [$user, $two] = disableSignedIn($this);
    $body = ['password' => $user['password']] + match ($kind) {
        'wrong code' => ['code' => Totp::wrongCode($two['secret'])],
        'used period' => ['code' => Totp::code($two['secret'])],   // the sign-in just used this period
        'short code' => ['code' => '12345'],
        'wrong recovery code' => ['recovery_code' => 'abcde-fghij'],
        'very long' => ['recovery_code' => str_repeat('a', 5000)],
        'a list' => ['code' => ['123456']],
    };

    $response = $this->browser->post(TF_DISABLE, $body);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect(array_keys($response->json('error.fields')))->toHaveCount(1)
        ->and(in_array('invalid', array_merge(...array_values($response->json('error.fields'))), true))->toBeTrue()
        ->and(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->not->toBeNull();
})->with(['wrong code', 'used period', 'short code', 'wrong recovery code', 'very long', 'a list']);

it('stops the second factor after 5 wrong ones in 15 minutes for the account, counted with the sign-in [NFR-SEC-05] (scenario 3d)', function () {
    [$user, $two] = disableSignedIn($this);

    foreach (range(1, 5) as $i) {
        $this->browser->post(TF_DISABLE, ['password' => $user['password'], 'recovery_code' => 'abcde-fghij'])->assertStatus(422);
    }

    $response = $this->browser->post(TF_DISABLE, disableBody($user, $two));
    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->not->toBeNull();
    // The sign-in side is stopped by the same count.
    TwoFactor::pending($this, $user, new AuthClient($this))->post('/api/v1/auth/two-factor-challenge', ['recovery_code' => $two['codes'][1]])->assertStatus(429);
});

it('answers 409 when two-factor is not turned on, an unconfirmed setup included [FR-INST-04] (scenario 4)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();
    $body = ['password' => $user['password'], 'code' => '123456'];
    $this->browser->post(TF_DISABLE, $body)->assertStatus(409)->assertJsonPath('error.code', 'two_factor_not_enabled');

    TwoFactor::plantUnconfirmed($user['user'], 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');
    $this->browser->post(TF_DISABLE, $body)->assertStatus(409)->assertJsonPath('error.code', 'two_factor_not_enabled');
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-04] (scenario 5)', function () {
    $this->browser->post(TF_DISABLE, ['password' => 'whatever whatever', 'code' => '123456'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 6)', function () {
    [$user, $two] = disableSignedIn($this);
    Accounts::suspend($user['institution']);

    $this->browser->post(TF_DISABLE, disableBody($user, $two))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->not->toBeNull();
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 7)', function () {
    [$user, $two] = disableSignedIn($this);

    $this->browser->post(TF_DISABLE, disableBody($user, $two), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->not->toBeNull();
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 8)', function () {
    disableSignedIn($this);

    $this->browser->postRaw(TF_DISABLE, '{"password": "x"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 10 requests a minute from one user [NFR-SEC-05] (scenario 9)', function () {
    [$user, $two] = disableSignedIn($this);

    // Turning two-factor on took two of the ten requests of the minute (setup and confirm). The first
    // disable succeeds; the others find it off (409), and still count.
    foreach (range(1, 8) as $i) {
        expect($this->browser->post(TF_DISABLE, disableBody($user, $two))->getStatusCode())->not->toBe(429);
    }

    $this->browser->post(TF_DISABLE, ['password' => $user['password'], 'code' => '123456'])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});

it('ends the session at the 5th wrong password in 15 minutes and keeps two-factor on [NFR-SEC-01, NFR-SEC-05] (scenario 3b)', function () {
    [$user, $two] = disableSignedIn($this);

    foreach (range(1, 4) as $i) {
        $this->browser->post(TF_DISABLE, ['password' => 'not the password at all', 'recovery_code' => $two['codes'][0]])->assertStatus(422);
    }

    $this->browser->post(TF_DISABLE, ['password' => 'not the password at all', 'recovery_code' => $two['codes'][0]])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
    expect(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->not->toBeNull();
});

it('answers 405 to another method than POST [NFR-SEC-01] (scenario 10)', function (string $method) {
    disableSignedIn($this);

    $this->browser->other($method, TF_DISABLE)->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['GET', 'PUT', 'PATCH', 'DELETE']);
