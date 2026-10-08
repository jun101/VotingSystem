<?php

use App\Actions\Auth\AttemptLogin;
use App\Actions\Auth\ClearTwoFactor;
use App\Actions\Auth\CompleteTwoFactorChallenge;
use App\Actions\Auth\ResetPassword;
use App\Auth\PasswordFailures;
use App\Auth\PendingSignIn;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Totp;
use Tests\Support\TwoFactor;

beforeEach(fn () => Accounts::reset());

function modelOf(array $account): User
{
    return User::withoutInstitutionScope()->where('uuid', $account['user'])->firstOrFail();
}

it('feeds the shared counter with a wrong password at sign-in, from any address, and with nothing else', function () {
    $account = Accounts::user();
    $user = modelOf($account);

    (new AuthClient($this))->fromAddress('10.1.0.1')->login($account['email'], 'wrong password here')->assertStatus(401);
    (new AuthClient($this))->fromAddress('10.1.0.2')->login($account['email'], 'wrong password here')->assertStatus(401);
    expect(RateLimiter::attempts(PasswordFailures::key($user)))->toBe(2);

    // A right password neither refuses nor clears.
    (new AuthClient($this))->fromAddress('10.1.0.3')->login($account['email'], $account['password'])->assertOk();
    expect(RateLimiter::attempts(PasswordFailures::key($user)))->toBe(2);

    // An unknown address feeds no account's counter.
    (new AuthClient($this))->login('nobody@example.test', 'wrong password here')->assertStatus(401);
    expect(RateLimiter::attempts(PasswordFailures::key($user)))->toBe(2);
});

it('answers 429 with a Retry-After on the settings routes once the counter is at five, whatever the password, and keeps the session', function () {
    $account = Accounts::user();
    $session = new AuthClient($this);
    $session->login($account['email'], $account['password'])->assertOk();

    foreach (range(1, 5) as $i) {
        (new AuthClient($this))->fromAddress("10.2.0.{$i}")->login($account['email'], 'wrong password here')->assertStatus(401);
    }

    foreach (['wrong password here', $account['password']] as $password) {
        $response = $session->post('/api/v1/auth/two-factor/disable', ['password' => $password]);
        $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(900);
    }

    $session->get('/api/v1/auth/me')->assertOk();
});

it('clears the counter on a right password only while it is below five', function () {
    $account = Accounts::user();
    $user = modelOf($account);
    $session = new AuthClient($this);
    $session->login($account['email'], $account['password'])->assertOk();

    RateLimiter::hit(PasswordFailures::key($user), 900);
    RateLimiter::hit(PasswordFailures::key($user), 900);
    $session->post('/api/v1/auth/two-factor/setup', ['password' => $account['password']])->assertOk();
    expect(RateLimiter::attempts(PasswordFailures::key($user)))->toBe(0);
});

it('keeps the key of the address the password was typed from in the pending state', function () {
    $user = modelOf(Accounts::user());
    $store = app('session.store');
    PendingSignIn::start($store, $user, 'login-failures:abc');

    expect(PendingSignIn::current($store)['fail'])->toBe('login-failures:abc');
});

it('clears the wrong-code counter when the second factor is cleared', function () {
    $user = modelOf(Accounts::user());
    $key = CompleteTwoFactorChallenge::accountKey($user);
    RateLimiter::hit($key, 900);
    RateLimiter::hit($key, 900);

    app(ClearTwoFactor::class)($user);

    expect(RateLimiter::attempts($key))->toBe(0);
});

it('clears the wrong-code counter when the password is reset', function () {
    $account = Accounts::user();
    $user = modelOf($account);
    $key = CompleteTwoFactorChallenge::accountKey($user);
    RateLimiter::hit($key, 900);
    $token = Accounts::token();
    Accounts::plantToken('password_reset_tokens', $account['email'], $token);

    app(ResetPassword::class)($token, 'A brand new passphrase 42');

    expect(RateLimiter::attempts($key))->toBe(0);
});

it('ends a pending sign-in when a sign-in without two-factor succeeds in the same browser', function () {
    $withFactor = Accounts::user();
    $plain = Accounts::user();
    $two = TwoFactor::enable($this, $withFactor);

    $browser = TwoFactor::pending($this, $withFactor);
    $browser->login($plain['email'], $plain['password'])->assertOk();
    $browser->post('/api/v1/auth/logout')->assertNoContent();

    // The first sign-in's pending state is gone: the code opens nothing.
    $browser->post('/api/v1/auth/two-factor-challenge', ['code' => Totp::code($two['secret'])])->assertStatus(401);
});

it('clears the failed-password counter of the address the password was typed from at a challenge success', function () {
    $account = Accounts::user();
    $user = modelOf($account);
    $two = TwoFactor::enable($this, $account);
    $key = AttemptLogin::failureKeyForUser($user, '10.3.0.1');

    (new AuthClient($this))->fromAddress('10.3.0.1')->login($account['email'], 'wrong password here')->assertStatus(401);
    expect(RateLimiter::attempts($key))->toBe(1);

    // The password typed from 10.3.0.1, the code sent from another address.
    $browser = TwoFactor::pending($this, $account, (new AuthClient($this))->fromAddress('10.3.0.1'));
    $browser->fromAddress('10.3.0.9')->post('/api/v1/auth/two-factor-challenge', ['code' => Totp::code($two['secret'])])->assertOk();

    expect(RateLimiter::attempts($key))->toBe(0);
});
