<?php

use Illuminate\Support\Facades\DB;
use Tests\Helpers\Browser;
use Tests\Support\Accounts;

/*
 * The sessions of the cookie-session routes, with the cookies really travelling (one
 * Browser per person). The application is the same instance for every request, as a worker
 * is under Octane (docs/design/architecture.md section 4.2).
 */

beforeEach(fn () => Accounts::reset());

it('does not let one request see the user or the session of the previous one on the same worker [NFR-SEC-03]', function () {
    $user = Accounts::user();
    $first = new Browser($this);
    $first->login($user['email'], $user['password'])->assertOk();
    $first->get('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user['user']);

    // Nobody signed in, no cookie at all.
    $stranger = new Browser($this);
    $stranger->get('/api/v1/auth/me')->assertStatus(401);
    $stranger->post('/api/v1/auth/verify-email/resend')->assertStatus(401);

    // And the first person is still known afterwards.
    $first->get('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user['user']);
});

it('serves two signed-in people in turn, each their own data [NFR-SEC-03]', function () {
    $marie = Accounts::user(['email' => 'marie@example.test']);
    $paul = Accounts::user(['email' => 'paul@example.test']);

    $a = new Browser($this, '10.0.0.1');
    $b = new Browser($this, '10.0.0.2');
    $a->login($marie['email'], $marie['password'])->assertOk();
    $b->login($paul['email'], $paul['password'])->assertOk();

    foreach (range(1, 3) as $_) {
        $a->get('/api/v1/auth/me')->assertJsonPath('data.email', 'marie@example.test');
        $b->get('/api/v1/auth/me')->assertJsonPath('data.email', 'paul@example.test');
    }
});

it('ends the session at sign-out: the old cookie opens nothing [FR-INST-04]', function () {
    $user = Accounts::user();
    $browser = new Browser($this);
    $browser->login($user['email'], $user['password'])->assertOk();
    $cookie = $browser->cookie(config('session.cookie'));

    $browser->post('/api/v1/auth/logout')->assertNoContent();

    // A thief with the old cookie.
    $thief = new Browser($this);
    (function () use ($cookie) {
        $this->cookies[config('session.cookie')] = $cookie;
    })->call($thief);
    $thief->get('/api/v1/auth/me')->assertStatus(401);
});

it('ends every other session of the user when the password is reset [FR-INST-04]', function () {
    $user = Accounts::user();
    $laptop = new Browser($this, '10.0.0.1');
    $phone = new Browser($this, '10.0.0.2');
    $laptop->login($user['email'], $user['password'])->assertOk();
    $phone->login($user['email'], $user['password'])->assertOk();

    // Straight after sign-in, with no other request in between.
    $token = Accounts::token();
    Accounts::plantToken('password_reset_tokens', $user['email'], $token);
    (new Browser($this, '10.0.0.3'))->post('/api/v1/auth/reset-password', ['token' => $token, 'password' => 'a brand new long password'])->assertNoContent();

    $laptop->get('/api/v1/auth/me')->assertStatus(401);
    $phone->get('/api/v1/auth/me')->assertStatus(401);
});

it('refuses the session of a user who was removed since sign-in [FR-INST-04]', function () {
    $user = Accounts::user();
    $browser = new Browser($this);
    $browser->login($user['email'], $user['password'])->assertOk();

    DB::connection(useMigratorConnection())->table('users')->where('uuid', $user['user'])->update(['deleted_at' => now()->format('Y-m-d H:i:s')]);

    $browser->get('/api/v1/auth/me')->assertStatus(401);
});

it('refuses a resend for a user whose institution was suspended since sign-in [FR-INST-06]', function () {
    $user = Accounts::user(['verified' => false]);
    $browser = new Browser($this);
    $browser->login($user['email'], $user['password'])->assertOk();

    DB::connection(useMigratorConnection())->table('institutions')->where('uuid', $user['institution'])->update(['suspended_at' => now()->format('Y-m-d H:i:s')]);

    $browser->post('/api/v1/auth/verify-email/resend')->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::mail())->toBe([]);
    $browser->get('/api/v1/auth/me')->assertStatus(401);
});

it('refuses a request whose X-XSRF-TOKEN is from another session [NFR-SEC-04]', function () {
    $user = Accounts::user();
    $mine = new Browser($this);
    $other = new Browser($this, '10.0.0.9');
    $mine->get('/api/v1/auth/csrf');
    $other->get('/api/v1/auth/csrf');

    $mine->post('/api/v1/auth/login', ['email' => $user['email'], 'password' => $user['password']], ['X-XSRF-TOKEN' => $other->cookie('XSRF-TOKEN')], csrf: false)
        ->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
});

it('does not accept the CSRF token from the body, from X-CSRF-TOKEN or from Sec-Fetch-Site [NFR-SEC-04]', function () {
    $user = Accounts::user();
    $browser = new Browser($this);
    $browser->get('/api/v1/auth/csrf');
    $token = $browser->cookie('XSRF-TOKEN');
    $credentials = ['email' => $user['email'], 'password' => $user['password']];

    $browser->post('/api/v1/auth/login', $credentials + ['_token' => $token], csrf: false)->assertStatus(419);
    $browser->post('/api/v1/auth/login', $credentials, ['X-CSRF-TOKEN' => $token], csrf: false)->assertStatus(419);
    $browser->post('/api/v1/auth/login', $credentials, ['Sec-Fetch-Site' => 'same-origin'], csrf: false)->assertStatus(419);
});

it('marks both cookies Secure outside local development, and neither in local development [NFR-SEC-04]', function () {
    $cookies = collect((new Browser($this))->get('/api/v1/auth/csrf')->headers->getCookies());

    expect($cookies)->toHaveCount(2)
        ->and($cookies->every(fn ($c) => $c->isSecure() === (bool) config('session.secure')))->toBeTrue();

    foreach (['local' => false, 'production' => true, 'testing' => true] as $env => $secure) {
        putenv("APP_ENV={$env}");
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $env;
        putenv('SESSION_SECURE_COOKIE');
        unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);

        expect((require base_path('config/session.php'))['secure'])->toBe($secure);
    }

    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
});
