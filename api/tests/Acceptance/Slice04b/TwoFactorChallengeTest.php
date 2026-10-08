<?php

/*
 * POST /api/v1/auth/two-factor-challenge — docs/api/auth/POST-auth-two-factor-challenge.md
 * Public route: it only ever finishes the sign-in that the session records.
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Totp;
use Tests\Support\TwoFactor;

const CHALLENGE = '/api/v1/auth/two-factor-challenge';

/** A user with two-factor on and a browser holding the pending sign-in. */
function challengeSetUp($test): array
{
    $user = Accounts::user();
    $two = TwoFactor::enable($test, $user);
    TwoFactor::pending($test, $user, $test->browser);

    return [$user, $two];
}

it('signs in with a right code and answers the current user [FR-INST-04] (scenario 1)', function () {
    [$user, $two] = challengeSetUp($this);
    $before = $this->browser->cookie(config('session.cookie'));

    $response = $this->browser->post(CHALLENGE, ['code' => Totp::code($two['secret'])]);

    $response->assertOk();
    expect(array_keys($response->json()))->toBe(['data'])
        ->and(array_keys($response->json('data')))->toEqualCanonicalizing(['id', 'name', 'email', 'role', 'email_verified', 'language', 'institution'])
        ->and($response->json('data.id'))->toBe($user['user'])
        ->and($response->getContent())->not->toContain($two['secret']);

    // Signed in, with a new session id, and `last_login_at` set now.
    expect($this->browser->cookie(config('session.cookie')))->not->toBe($before)
        ->and(TwoFactor::row($user['user'])['last_login_at'])->not->toBeNull();
    $this->browser->get('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user['user']);
});

it('accepts a code with spaces, and the code of the period before and the period after [FR-INST-04] (scenario 1)', function (int $offset) {
    [$user, $two] = challengeSetUp($this);
    $code = Totp::code($two['secret'], Totp::step() + $offset);

    $this->browser->post(CHALLENGE, ['code' => substr($code, 0, 3).' '.substr($code, 3)])->assertOk();
})->with([[-1], [0], [1]]);

it('signs in with a right recovery code, which then works no more [FR-INST-04] (scenario 2)', function () {
    [$user, $two] = challengeSetUp($this);

    $this->browser->post(CHALLENGE, ['recovery_code' => $two['codes'][0]])->assertOk()->assertJsonPath('data.id', $user['user']);

    // Seven are left, the used one is gone.
    $this->browser->get('/api/v1/auth/two-factor')->assertOk()->assertJsonPath('data.recovery_codes_left', 7);
    $again = TwoFactor::pending($this, $user, new AuthClient($this));
    $again->post(CHALLENGE, ['recovery_code' => $two['codes'][0]])->assertStatus(422);
    $again->post(CHALLENGE, ['recovery_code' => $two['codes'][1]])->assertOk();
});

it('accepts a recovery code in any letter case, with or without the dash [FR-INST-04] (scenario 2)', function (string $shape) {
    [$user, $two] = challengeSetUp($this);
    $code = $two['codes'][2];
    $sent = match ($shape) {
        'upper case' => strtoupper($code),
        'no dash' => str_replace('-', '', $code),
        'with spaces' => ' '.str_replace('-', ' ', $code).' ',
    };

    $this->browser->post(CHALLENGE, ['recovery_code' => $sent])->assertOk();
})->with(['upper case', 'no dash', 'with spaces']);

it('checks the recovery code when both fields are sent [FR-INST-04] (scenario 2)', function () {
    [$user, $two] = challengeSetUp($this);

    $this->browser->post(CHALLENGE, ['code' => Totp::code($two['secret']), 'recovery_code' => 'wrong-code'])
        ->assertStatus(422);
    expect($this->browser->post(CHALLENGE, ['code' => Totp::wrongCode($two['secret']), 'recovery_code' => $two['codes'][0]])->getStatusCode())->toBe(200);
});

it('answers 422 when neither field is sent [FR-INST-04] (scenario 3)', function () {
    [$user, $two] = challengeSetUp($this);

    $response = $this->browser->post(CHALLENGE, []);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.code'))->toContain('required');
    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
});

it('answers 422 for a wrong code, one that is not 6 digits, or an already used period [FR-INST-04] (scenario 4)', function (string $kind) {
    [$user, $two] = challengeSetUp($this);
    $code = match ($kind) {
        'wrong' => Totp::wrongCode($two['secret']),
        'short' => '12345',
        'long' => '1234567',
        'letters' => 'abcdef',
        'old period' => Totp::code($two['secret'], Totp::step() - 3),
        'next but one' => Totp::code($two['secret'], Totp::step() + 3),
        'very long' => str_repeat('1', 5000),
    };

    $response = $this->browser->post(CHALLENGE, ['code' => $code]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.code'))->toContain('invalid');
    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
})->with(['wrong', 'short', 'long', 'letters', 'old period', 'next but one', 'very long']);

it('refuses a code that was already used, even in the same period [FR-INST-04, NFR-SEC-01] (scenario 4)', function () {
    [$user, $two] = challengeSetUp($this);
    $code = Totp::code($two['secret']);
    $this->browser->post(CHALLENGE, ['code' => $code])->assertOk();

    $second = TwoFactor::pending($this, $user, new AuthClient($this));
    $second->post(CHALLENGE, ['code' => $code])->assertStatus(422)->assertJsonPath('error.fields.code.0', 'invalid');

    // The next period's code is accepted once.
    $second->post(CHALLENGE, ['code' => Totp::code($two['secret'], Totp::step() + 1)])->assertOk();
});

it('answers 422 for a wrong or used recovery code [FR-INST-04] (scenario 5)', function () {
    [$user, $two] = challengeSetUp($this);

    $response = $this->browser->post(CHALLENGE, ['recovery_code' => 'abcde-fghij']);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.recovery_code'))->toContain('invalid');
});

it('answers 401 when there is no pending sign-in [FR-INST-04] (scenario 6)', function () {
    $user = Accounts::user();
    $two = TwoFactor::enable($this, $user);

    $response = $this->browser->post(CHALLENGE, ['code' => Totp::code($two['secret'])]);

    $response->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message']);
});

it('answers 401 when the pending sign-in is older than 5 minutes [FR-INST-04] (scenario 6)', function () {
    [$user, $two] = challengeSetUp($this);

    $this->travel(5)->minutes();
    $this->travel(1)->seconds();

    $this->browser->post(CHALLENGE, ['code' => Totp::code($two['secret'])])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
});

it('ends the pending sign-in at the fifth wrong code, even for a right one after it [FR-INST-04, NFR-SEC-05] (scenario 6)', function () {
    [$user, $two] = challengeSetUp($this);

    foreach (range(1, 5) as $i) {
        $this->browser->post(CHALLENGE, ['code' => Totp::wrongCode($two['secret'])])->assertStatus(422);
    }

    $this->browser->post(CHALLENGE, ['code' => Totp::code($two['secret'])])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
});

it('counts a wrong recovery code like a wrong code [FR-INST-04] (scenario 6)', function () {
    [$user, $two] = challengeSetUp($this);

    foreach (range(1, 5) as $i) {
        $this->browser->post(CHALLENGE, ['recovery_code' => 'abcde-fghij'])->assertStatus(422);
    }

    $this->browser->post(CHALLENGE, ['recovery_code' => $two['codes'][0]])->assertStatus(401);
});

it('answers 403 and signs nobody in when the institution was suspended since the first step [FR-INST-06] (scenario 7)', function () {
    [$user, $two] = challengeSetUp($this);
    Accounts::suspend($user['institution']);

    $this->browser->post(CHALLENGE, ['code' => Totp::code($two['secret'])])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 8)', function () {
    [$user, $two] = challengeSetUp($this);

    $this->browser->post(CHALLENGE, ['code' => Totp::code($two['secret'])], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 9)', function () {
    $this->browser->postRaw(CHALLENGE, '{"code": "123456"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 10 requests a minute from one address [NFR-SEC-05] (scenario 10)', function () {
    foreach (range(1, 10) as $i) {
        $this->browser->post(CHALLENGE, ['code' => '123456'])->assertStatus(401);
    }

    $response = $this->browser->post(CHALLENGE, ['code' => '123456']);

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('answers 405 to another method than POST [NFR-SEC-01] (scenario 11)', function (string $method) {
    $response = $this->browser->other($method, CHALLENGE);

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect($response->headers->get('Allow'))->toContain('POST');
})->with(['GET', 'PUT', 'PATCH', 'DELETE']);

it('grants a session only for the user of the pending sign-in, whatever the body names [FR-INST-05, FR-INST-04]', function () {
    [$user, $two] = challengeSetUp($this);
    $other = Accounts::user();

    $response = $this->browser->post(CHALLENGE, [
        'code' => Totp::code($two['secret']),
        'email' => $other['email'],
        'user' => $other['user'],
        'id' => $other['user'],
        'institution' => $other['institution'],
    ]);

    $response->assertOk()->assertJsonPath('data.id', $user['user']);
});

it('does not log a code, a recovery code or the address [FR-INST-04, NFR-SEC-05]', function () {
    $user = Accounts::user(['email' => 'challenge.quiet@example.test']);
    $two = TwoFactor::enable($this, $user);
    TwoFactor::pending($this, $user, $this->browser);
    $code = Totp::code($two['secret']);

    $this->browser->post(CHALLENGE, ['code' => $code])->assertOk();
    $again = TwoFactor::pending($this, $user, new AuthClient($this));
    $again->post(CHALLENGE, ['recovery_code' => $two['codes'][0]])->assertOk();

    foreach (glob(storage_path('logs/*.log')) ?: [] as $log) {
        $content = file_get_contents($log);
        expect($content)->not->toContain('challenge.quiet@example.test')->not->toContain($two['codes'][0])->not->toContain($two['secret']);
    }
});

it('stops guesses for the whole account after 5 wrong codes in 15 minutes, from any session or address [FR-INST-04, NFR-SEC-05] (scenario 10)', function () {
    $user = Accounts::user();
    $two = TwoFactor::enable($this, $user);

    $first = TwoFactor::pending($this, $user, (new AuthClient($this))->fromAddress('10.0.0.1'));
    foreach (range(1, 5) as $i) {
        $first->post(CHALLENGE, ['code' => Totp::wrongCode($two['secret'])])->assertStatus(422);
    }

    // Another browser, another address, a fresh pending sign-in with the right password: still stopped.
    $second = TwoFactor::pending($this, $user, (new AuthClient($this))->fromAddress('10.0.0.2'));
    $response = $second->post(CHALLENGE, ['code' => Totp::code($two['secret'])]);
    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
    $second->get('/api/v1/auth/me')->assertStatus(401);

    // Recovery codes are stopped as well, and so is a wrong code.
    $second->post(CHALLENGE, ['recovery_code' => $two['codes'][0]])->assertStatus(429);

    // After the window the person gets in again.
    $this->travel(16)->minutes();
    $third = TwoFactor::pending($this, $user, (new AuthClient($this))->fromAddress('10.0.0.3'));
    $third->post(CHALLENGE, ['recovery_code' => $two['codes'][0]])->assertOk();
});

it('does not reset the count when the password is typed again in the same session [FR-INST-04, NFR-SEC-05] (scenario 10)', function () {
    [$user, $two] = challengeSetUp($this);

    foreach (range(1, 4) as $i) {
        $this->browser->post(CHALLENGE, ['code' => Totp::wrongCode($two['secret'])])->assertStatus(422);
    }
    TwoFactor::pending($this, $user, $this->browser);   // the password again, same browser and session
    $this->browser->post(CHALLENGE, ['code' => Totp::wrongCode($two['secret'])])->assertStatus(422);   // the fifth

    $this->browser->post(CHALLENGE, ['code' => Totp::code($two['secret'])])->assertStatus(429);
});

it('does not count a request with no field, and a success clears the count [FR-INST-04] (scenario 10)', function () {
    [$user, $two] = challengeSetUp($this);

    foreach (range(1, 4) as $i) {
        $this->browser->post(CHALLENGE, ['code' => Totp::wrongCode($two['secret'])])->assertStatus(422);
    }
    $this->browser->post(CHALLENGE, [])->assertStatus(422);
    $this->browser->post(CHALLENGE, ['code' => Totp::code($two['secret'])])->assertOk();

    // The count was cleared by the success: four more wrong codes are still answered 422.
    $again = TwoFactor::pending($this, $user, new AuthClient($this));
    foreach (range(1, 4) as $i) {
        $again->post(CHALLENGE, ['code' => Totp::wrongCode($two['secret'])])->assertStatus(422);
    }
});

it('answers 401 when the password changed since the first step [FR-INST-04, NFR-SEC-01] (scenario 12)', function () {
    [$user, $two] = challengeSetUp($this);
    Illuminate\Support\Facades\DB::connection(useMigratorConnection())->table('users')->where('uuid', $user['user'])
        ->update(['password' => Illuminate\Support\Facades\Hash::make('a brand new password, reset by the owner')]);

    $this->browser->post(CHALLENGE, ['code' => Totp::code($two['secret'])])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
});

it('clears the failed-password count at a success, as a sign-in without two-factor does [FR-INST-04, NFR-SEC-05]', function () {
    $user = Accounts::user();
    $two = TwoFactor::enable($this, $user);
    foreach (range(1, 4) as $i) {
        $this->browser->login($user['email'], 'not the password at all')->assertStatus(401);
    }
    TwoFactor::pending($this, $user, $this->browser);
    $this->browser->post(CHALLENGE, ['code' => Totp::code($two['secret'])])->assertOk();

    // Without the clearing, the second typo here would answer 429.
    $other = new AuthClient($this);
    $other->login($user['email'], 'not the password at all')->assertStatus(401);
    $other->login($user['email'], 'not the password at all')->assertStatus(401);
});

it('clears the failed-password count of the address the password was typed from, even if the code comes from another [FR-INST-04, NFR-SEC-05]', function () {
    $user = Accounts::user();
    $two = TwoFactor::enable($this, $user);
    $phone = (new AuthClient($this))->fromAddress('10.1.0.1');
    foreach (range(1, 4) as $i) {
        $phone->login($user['email'], 'not the password at all')->assertStatus(401);
    }
    TwoFactor::pending($this, $user, $phone);
    $phone->fromAddress('10.2.0.2');   // the network changed between the two steps
    $phone->post(CHALLENGE, ['code' => Totp::code($two['secret'])])->assertOk();

    // The count of the first address was cleared: two more typos from it are still answered 401.
    $again = (new AuthClient($this))->fromAddress('10.1.0.1');
    $again->login($user['email'], 'not the password at all')->assertStatus(401);
    $again->login($user['email'], 'not the password at all')->assertStatus(401);
});

it('clears the account\'s wrong-code count when the password is reset, so the real user is not locked out [FR-INST-04] (scenario 10)', function () {
    $user = Accounts::user();
    $two = TwoFactor::enable($this, $user);
    $attacker = TwoFactor::pending($this, $user, new AuthClient($this));
    foreach (range(1, 5) as $i) {
        $attacker->post(CHALLENGE, ['code' => Totp::wrongCode($two['secret'])])->assertStatus(422);
    }
    expect(TwoFactor::pending($this, $user, new AuthClient($this))->post(CHALLENGE, ['code' => Totp::code($two['secret'])])->getStatusCode())->toBe(429);

    $token = Accounts::token();
    Accounts::plantToken('password_reset_tokens', $user['email'], $token);
    (new AuthClient($this))->post('/api/v1/auth/reset-password', ['token' => $token, 'password' => 'a brand new long password'])->assertSuccessful();

    $real = TwoFactor::pending($this, ['email' => $user['email'], 'password' => 'a brand new long password'] + $user, new AuthClient($this));
    $real->post(CHALLENGE, ['code' => Totp::code($two['secret'])])->assertOk();
});

it('clears the account\'s wrong-code count when an owner or the operator turns its two-factor off [FR-INST-04] (scenario 10)', function () {
    $t = Tests\Support\Team::two();
    $two = TwoFactor::enable($this, $t['a']['manager']);
    $attacker = TwoFactor::pending($this, $t['a']['manager'], new AuthClient($this));
    foreach (range(1, 5) as $i) {
        $attacker->post(CHALLENGE, ['code' => Totp::wrongCode($two['secret'])])->assertStatus(422);
    }
    Tests\Support\Team::signIn($this, $t['a']['owner']);
    $this->browser->post("/api/v1/users/{$t['a']['manager']['user']}/two-factor/reset", ['password' => $t['a']['owner']['password']])->assertNoContent();

    // The person sets it up again, and their first sign-in with a code is not refused for the old attempts.
    $manager = new AuthClient($this);
    $manager->login($t['a']['manager']['email'], $t['a']['manager']['password'])->assertOk();
    $secret = $manager->post('/api/v1/auth/two-factor/setup', ['password' => $t['a']['manager']['password']])->assertOk()->json('data.secret');
    $manager->post('/api/v1/auth/two-factor/confirm', ['code' => Totp::code($secret)])->assertOk();
    $manager->post('/api/v1/auth/logout')->assertNoContent();
    TwoFactor::clearLastStep($t['a']['manager']['user']);

    TwoFactor::pending($this, $t['a']['manager'], new AuthClient($this))->post(CHALLENGE, ['code' => Totp::code($secret)])->assertOk();
});
