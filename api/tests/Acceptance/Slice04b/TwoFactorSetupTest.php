<?php

/*
 * POST /api/v1/auth/two-factor/setup — docs/api/auth/POST-auth-two-factor-setup.md
 * Route in the tenant suite: api/v1/auth/two-factor/setup (the user's own, no record named).
 */

use Tests\Support\Accounts;
use Tests\Support\Totp;
use Tests\Support\TwoFactor;

const TF_SETUP = '/api/v1/auth/two-factor/setup';

function setupSignedIn($test): array
{
    $user = Accounts::user(['email' => 'marie@flamboyants.example']);
    $test->browser->login($user['email'], $user['password'])->assertOk();

    return $user;
}

it('issues a secret and the link of the authenticator application [FR-INST-04] (scenario 1)', function () {
    $user = setupSignedIn($this);

    $response = $this->browser->post(TF_SETUP, ['password' => $user['password']])->assertOk();

    expect(array_keys($response->json()))->toBe(['data'])
        ->and(array_keys($response->json('data')))->toEqualCanonicalizing(['secret', 'otpauth_url'])
        ->and($response->json('data.secret'))->toMatch('/^[A-Z2-7]{32}$/');

    $url = parse_url($response->json('data.otpauth_url'));
    parse_str($url['query'], $query);
    expect($url['scheme'])->toBe('otpauth')
        ->and($url['host'])->toBe('totp')
        ->and(rawurldecode(ltrim($url['path'], '/')))->toBe('New Voting System:marie@flamboyants.example')
        ->and($query)->toMatchArray(['secret' => $response->json('data.secret'), 'issuer' => 'New Voting System', 'algorithm' => 'SHA1', 'digits' => '6', 'period' => '30']);

    // Stored encrypted and not active: sign-in still asks for the password only.
    expect(TwoFactor::storedSecret($user['user']))->toBe($response->json('data.secret'))
        ->and(TwoFactor::row($user['user'])['two_factor_secret'])->not->toContain($response->json('data.secret'))
        ->and(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->toBeNull();
    $this->browser->get('/api/v1/auth/two-factor')->assertOk()->assertJsonPath('data.setup_started', true)->assertJsonPath('data.enabled', false);
});

it('replaces the secret of a setup that was started and not confirmed [FR-INST-04] (scenario 2)', function () {
    $user = setupSignedIn($this);
    $first = $this->browser->post(TF_SETUP, ['password' => $user['password']])->assertOk()->json('data.secret');

    $second = $this->browser->post(TF_SETUP, ['password' => $user['password']])->assertOk()->json('data.secret');

    expect($second)->not->toBe($first)
        ->and(TwoFactor::storedSecret($user['user']))->toBe($second);
    // The old secret's code no longer confirms.
    $this->browser->post('/api/v1/auth/two-factor/confirm', ['code' => Totp::code($first)])->assertStatus(422);
    $this->browser->post('/api/v1/auth/two-factor/confirm', ['code' => Totp::code($second)])->assertOk();
});

it('answers 422 when the password is missing [FR-INST-04] (scenario 3)', function () {
    $user = setupSignedIn($this);

    $response = $this->browser->post(TF_SETUP, []);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.password'))->toContain('required')
        ->and(TwoFactor::storedSecret($user['user']))->toBeNull();
});

it('answers 422, not 401, when the password is wrong, and issues no secret [NFR-SEC-01] (scenario 4)', function () {
    $user = setupSignedIn($this);

    $response = $this->browser->post(TF_SETUP, ['password' => 'not the password at all']);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.password'))->toContain('incorrect')
        ->and(TwoFactor::storedSecret($user['user']))->toBeNull();
    // Still signed in.
    $this->browser->get('/api/v1/auth/me')->assertOk();
});

it('answers 409 when two-factor is already turned on, and keeps the secret [FR-INST-04] (scenario 5)', function () {
    $user = Accounts::user();
    $two = TwoFactor::enable($this, $user);
    TwoFactor::pending($this, $user, $this->browser);
    $this->browser->post('/api/v1/auth/two-factor-challenge', ['code' => Totp::code($two['secret'])])->assertOk();

    $this->browser->post(TF_SETUP, ['password' => $user['password']])->assertStatus(409)->assertJsonPath('error.code', 'two_factor_already_enabled');
    expect(TwoFactor::storedSecret($user['user']))->toBe($two['secret']);
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-04] (scenario 6)', function () {
    $this->browser->post(TF_SETUP, ['password' => 'whatever whatever'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 7)', function () {
    $user = setupSignedIn($this);
    Accounts::suspend($user['institution']);

    $this->browser->post(TF_SETUP, ['password' => $user['password']])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(TwoFactor::storedSecret($user['user']))->toBeNull();
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 8)', function () {
    $user = setupSignedIn($this);

    $this->browser->post(TF_SETUP, ['password' => $user['password']], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(TwoFactor::storedSecret($user['user']))->toBeNull();
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 9)', function () {
    setupSignedIn($this);

    $this->browser->postRaw(TF_SETUP, '{"password": "x"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 10 requests a minute from one user [NFR-SEC-05] (scenario 10)', function () {
    $user = setupSignedIn($this);

    foreach (range(1, 10) as $i) {
        $this->browser->post(TF_SETUP, ['password' => $user['password']])->assertOk();
    }

    $response = $this->browser->post(TF_SETUP, ['password' => $user['password']]);

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('ends the session at the 5th wrong password in 15 minutes, and a right password clears the count [NFR-SEC-01, NFR-SEC-05] (scenario 4b)', function () {
    $user = setupSignedIn($this);

    foreach (range(1, 4) as $i) {
        $this->browser->post(TF_SETUP, ['password' => 'not the password at all'])->assertStatus(422);
    }
    // A right password clears the count: four more wrong ones are answered 422 again.
    $this->browser->post(TF_SETUP, ['password' => $user['password']])->assertOk();
    foreach (range(1, 4) as $i) {
        $this->browser->post(TF_SETUP, ['password' => 'not the password at all'])->assertStatus(422);
    }

    $response = $this->browser->post(TF_SETUP, ['password' => 'not the password at all']);

    $response->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
    expect(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->toBeNull();
});

it('answers 405 to another method than POST [NFR-SEC-01] (scenario 11)', function (string $method) {
    setupSignedIn($this);

    $this->browser->other($method, TF_SETUP)->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['GET', 'PUT', 'PATCH', 'DELETE']);
