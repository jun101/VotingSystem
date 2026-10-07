<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Helpers\Browser;
use Tests\Support\Accounts;

/*
 * AUTH_RATE_LIMIT_FACTOR multiplies every number of the auth limiters (docs/api/auth/).
 */

beforeEach(fn () => Accounts::reset());

/** The limits a named limiter gives for a request. */
function limitsOf(string $name, array $input = []): array
{
    $request = Request::create('/', 'POST', $input, server: ['REMOTE_ADDR' => '203.0.113.7']);
    $limit = RateLimiter::limiter($name)($request);

    return array_map(fn ($l) => $l->maxAttempts, is_array($limit) ? $limit : [$limit]);
}

it('declares the numbers of the endpoint files when the factor is 1 [NFR-SEC-05]', function () {
    expect(config('auth.rate_limit_factor'))->toBe(1)
        ->and(limitsOf('auth-csrf'))->toBe([60])
        ->and(limitsOf('auth-register'))->toBe([10])
        ->and(limitsOf('auth-login'))->toBe([10])
        ->and(limitsOf('auth-verify-email'))->toBe([10])
        ->and(limitsOf('auth-reset-password'))->toBe([10])
        ->and(limitsOf('auth-resend'))->toBe([3])
        ->and(limitsOf('auth-forgot-password', ['email' => 'a@example.test']))->toBe([5, 3]);
});

it('multiplies every number by the factor [NFR-SEC-05]', function () {
    config(['auth.rate_limit_factor' => 100]);

    expect(limitsOf('auth-csrf'))->toBe([6000])
        ->and(limitsOf('auth-register'))->toBe([1000])
        ->and(limitsOf('auth-login'))->toBe([1000])
        ->and(limitsOf('auth-verify-email'))->toBe([1000])
        ->and(limitsOf('auth-reset-password'))->toBe([1000])
        ->and(limitsOf('auth-resend'))->toBe([300])
        ->and(limitsOf('auth-forgot-password', ['email' => 'a@example.test']))->toBe([500, 300]);
});

it('also multiplies the failed sign-in attempts per email and address [NFR-SEC-05]', function () {
    config(['auth.rate_limit_factor' => 2]);
    $user = Accounts::user();
    $browser = new Browser($this);

    foreach (range(1, 10) as $_) {
        // The per-address limit of the sign-in route is 20 now; the failures count to 10.
        $browser->login($user['email'], 'a wrong password, long enough')->assertStatus(401);
    }

    $browser->login($user['email'], $user['password'])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});

it('treats an unset or empty factor as 1 [NFR-SEC-05]', function () {
    $config = require base_path('config/auth.php');

    expect($config['rate_limit_factor'])->toBe(1);

    foreach (['', '0', 'abc', '-3'] as $value) {
        putenv("AUTH_RATE_LIMIT_FACTOR={$value}");
        $_ENV['AUTH_RATE_LIMIT_FACTOR'] = $_SERVER['AUTH_RATE_LIMIT_FACTOR'] = $value;

        expect((require base_path('config/auth.php'))['rate_limit_factor'])->toBe(1);
    }

    putenv('AUTH_RATE_LIMIT_FACTOR=1');
    $_ENV['AUTH_RATE_LIMIT_FACTOR'] = $_SERVER['AUTH_RATE_LIMIT_FACTOR'] = '1';
});

it('clears the failed attempts after a good sign-in [NFR-SEC-05]', function () {
    $user = Accounts::user();
    $browser = new Browser($this);

    foreach (range(1, 4) as $_) {
        $browser->login($user['email'], 'a wrong password, long enough')->assertStatus(401);
    }
    $browser->login($user['email'], $user['password'])->assertOk();

    foreach (range(1, 4) as $_) {
        $browser->login($user['email'], 'a wrong password, long enough')->assertStatus(401);
    }
});

it('holds no email address in the keys of its counters [NFR-OPS-04]', function () {
    $user = Accounts::user(['email' => 'secret.person@example.test']);
    $browser = new Browser($this);
    $browser->login($user['email'], 'a wrong password, long enough')->assertStatus(401);
    $browser->post('/api/v1/auth/forgot-password', ['email' => $user['email']])->assertNoContent();

    $keys = array_keys((fn () => $this->storage)->call(app('cache')->store()->getStore()));

    expect($keys)->not->toBe([])
        ->and(implode("\n", $keys))->not->toContain('secret.person')->not->toContain('example.test');
});
