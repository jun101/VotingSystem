<?php

/*
 * POST /api/v1/auth/two-factor/confirm — docs/api/auth/POST-auth-two-factor-confirm.md
 * Route in the tenant suite: api/v1/auth/two-factor/confirm (the user's own, no record named).
 */

use Illuminate\Support\Facades\Crypt;
use Tests\Support\Accounts;
use Tests\Support\Totp;
use Tests\Support\TwoFactor;

const TF_CONFIRM = '/api/v1/auth/two-factor/confirm';

/** A signed-in user who has started a setup. Returns [user, secret]. */
function confirmSetUp($test): array
{
    $user = Accounts::user();
    $test->browser->login($user['email'], $user['password'])->assertOk();
    $secret = $test->browser->post('/api/v1/auth/two-factor/setup', ['password' => $user['password']])->assertOk()->json('data.secret');

    return [$user, $secret];
}

it('turns two-factor on and returns eight recovery codes [FR-INST-04] (scenario 1)', function () {
    [$user, $secret] = confirmSetUp($this);

    $response = $this->browser->post(TF_CONFIRM, ['code' => Totp::code($secret)])->assertOk();

    $codes = $response->json('data.recovery_codes');
    expect(array_keys($response->json()))->toBe(['data'])
        ->and(array_keys($response->json('data')))->toBe(['recovery_codes'])
        ->and($codes)->toHaveCount(8)
        ->and(array_unique($codes))->toHaveCount(8);
    foreach ($codes as $code) {
        expect($code)->toMatch('/^[a-z0-9]{5}-[a-z0-9]{5}$/');
    }

    $row = TwoFactor::row($user['user']);
    expect($row['two_factor_confirmed_at'])->not->toBeNull()
        ->and((int) $row['two_factor_last_step'])->toBeGreaterThanOrEqual(Totp::step() - 1)
        ->and((int) $row['two_factor_last_step'])->toBeLessThanOrEqual(Totp::step() + 1);
    $this->browser->get('/api/v1/auth/two-factor')->assertOk()
        ->assertJsonPath('data.enabled', true)->assertJsonPath('data.recovery_codes_left', 8);
});

it('stores only keyed hashes of the recovery codes, never the codes [FR-INST-04] (scenario 1)', function () {
    [$user, $secret] = confirmSetUp($this);
    $codes = $this->browser->post(TF_CONFIRM, ['code' => Totp::code($secret)])->assertOk()->json('data.recovery_codes');

    $hashes = TwoFactor::storedRecoveryHashes($user['user']);
    $raw = (string) Illuminate\Support\Facades\DB::connection(useMigratorConnection())->table('users')->where('uuid', $user['user'])->value('two_factor_recovery_codes');

    expect($hashes)->toHaveCount(8)
        ->and(array_unique($hashes))->toHaveCount(8);
    foreach ($codes as $code) {
        $plain = str_replace('-', '', $code);
        expect($hashes)->not->toContain($code)->not->toContain($plain)
            ->and($hashes)->not->toContain(hash('sha256', $plain))     // not a plain, unkeyed hash
            ->and($raw)->not->toContain($code);
        expect(str_contains(json_encode($hashes), $plain))->toBeFalse();
    }
    expect(Crypt::decryptString($raw))->toBeString();
});

it('accepts the code of the previous or the next period [FR-INST-04] (scenario 1)', function (int $offset) {
    [$user, $secret] = confirmSetUp($this);

    $this->browser->post(TF_CONFIRM, ['code' => Totp::code($secret, Totp::step() + $offset)])->assertOk();
})->with([[-1], [1]]);

it('answers 422 when the code is missing [FR-INST-04] (scenario 2)', function () {
    [$user, $secret] = confirmSetUp($this);

    $response = $this->browser->post(TF_CONFIRM, []);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.code'))->toContain('required')
        ->and(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->toBeNull();
});

it('answers 422 for a wrong code or one that is not 6 digits, and the person can try again [FR-INST-04] (scenario 3)', function (string $kind) {
    [$user, $secret] = confirmSetUp($this);
    $code = match ($kind) {
        'wrong' => Totp::wrongCode($secret),
        'short' => '12345',
        'letters' => 'abcdef',
        'old period' => Totp::code($secret, Totp::step() - 3),
        'very long' => str_repeat('1', 5000),
        'a number' => 123456,
        'a list' => ['123456'],
    };

    $response = $this->browser->post(TF_CONFIRM, ['code' => $code]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.code'))->toContain('invalid')
        ->and(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->toBeNull();

    // The setup is still there.
    $this->browser->post(TF_CONFIRM, ['code' => Totp::code($secret)])->assertOk();
})->with(['wrong', 'short', 'letters', 'old period', 'very long', 'a number', 'a list']);

it('answers 409 when no setup was started [FR-INST-04] (scenario 4)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $this->browser->post(TF_CONFIRM, ['code' => '123456'])->assertStatus(409)->assertJsonPath('error.code', 'two_factor_not_started');
});

it('answers 409 when two-factor is already turned on, and returns no codes [FR-INST-04] (scenario 5)', function () {
    $user = Accounts::user();
    $two = TwoFactor::enable($this, $user);
    TwoFactor::pending($this, $user, $this->browser);
    $this->browser->post('/api/v1/auth/two-factor-challenge', ['code' => Totp::code($two['secret'])])->assertOk();

    $response = $this->browser->post(TF_CONFIRM, ['code' => Totp::code($two['secret'], Totp::step() + 1)]);

    $response->assertStatus(409)->assertJsonPath('error.code', 'two_factor_already_enabled');
    expect($response->getContent())->not->toContain('recovery_codes');
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-04] (scenario 6)', function () {
    $this->browser->post(TF_CONFIRM, ['code' => '123456'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 7)', function () {
    [$user, $secret] = confirmSetUp($this);
    Accounts::suspend($user['institution']);

    $this->browser->post(TF_CONFIRM, ['code' => Totp::code($secret)])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->toBeNull();
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 8)', function () {
    [$user, $secret] = confirmSetUp($this);

    $this->browser->post(TF_CONFIRM, ['code' => Totp::code($secret)], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 9)', function () {
    confirmSetUp($this);

    $this->browser->postRaw(TF_CONFIRM, '{"code": "123456"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 10 requests a minute from one user [NFR-SEC-05] (scenario 10)', function () {
    [$user, $secret] = confirmSetUp($this);   // one request of the 10 was the setup

    foreach (range(1, 9) as $i) {
        $this->browser->post(TF_CONFIRM, ['code' => Totp::wrongCode($secret)])->assertStatus(422);
    }

    $response = $this->browser->post(TF_CONFIRM, ['code' => Totp::code($secret)]);

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->toBeNull();
});

it('answers 405 to another method than POST [NFR-SEC-01] (scenario 11)', function (string $method) {
    confirmSetUp($this);

    $this->browser->other($method, TF_CONFIRM)->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['GET', 'PUT', 'PATCH', 'DELETE']);
