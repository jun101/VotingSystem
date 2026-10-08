<?php

/*
 * The password failures of an account — docs/api/auth/POST-auth-two-factor-setup.md ("Password
 * failures"), POST-auth-login.md, POST-auth-two-factor-disable.md, ...-recovery-codes.md and
 * docs/api/users/POST-users-{user}-two-factor-reset.md: one counter per account (5 in 15 minutes),
 * fed by wrong passwords at sign-in (any address) and on the settings routes.
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Team;
use Tests\Support\TwoFactor;

function guessAtLogin($test, array $user, int $times): void
{
    foreach (range(1, $times) as $i) {
        (new AuthClient($test))->fromAddress("10.9.0.{$i}")->login($user['email'], 'not the password at all')->assertStatus(401);
    }
}

it('counts wrong passwords at sign-in, from any address, toward the lockout of the settings routes [NFR-SEC-01, NFR-SEC-05] (login scenario 4)', function () {
    $user = Accounts::user();
    $session = new AuthClient($this);
    $session->login($user['email'], $user['password'])->assertOk();   // a session that is already open (stolen?)

    guessAtLogin($this, $user, 5);   // five different addresses: no per-address limit is reached

    $response = $session->post('/api/v1/auth/two-factor/setup', ['password' => $user['password']]);
    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
    // The session is kept.
    $session->get('/api/v1/auth/me')->assertOk();
});

it('never refuses a sign-in because of that counter, and a sign-in does not clear it [NFR-SEC-01] (login scenario 1)', function () {
    $user = Accounts::user();
    guessAtLogin($this, $user, 5);

    $browser = (new AuthClient($this))->fromAddress('10.8.0.1');
    $browser->login($user['email'], $user['password'])->assertOk();

    // Signed in with the right password, and still locked out of the settings routes.
    $browser->post('/api/v1/auth/two-factor/setup', ['password' => $user['password']])->assertStatus(429);
});

it('shares one counter between the settings routes, and the fifth wrong password ends the session [NFR-SEC-01] (scenario 4b)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();
    $wrong = ['password' => 'not the password at all'];

    $this->browser->post('/api/v1/auth/two-factor/setup', $wrong)->assertStatus(422);
    $this->browser->post('/api/v1/auth/two-factor/setup', $wrong)->assertStatus(422);
    $this->browser->post('/api/v1/auth/two-factor/disable', $wrong + ['code' => '123456'])->assertStatus(422);
    $this->browser->post('/api/v1/auth/two-factor/disable', $wrong + ['code' => '123456'])->assertStatus(422);
    $this->browser->post('/api/v1/auth/two-factor/recovery-codes', $wrong + ['code' => '123456'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
});

it('answers 429 and keeps the session for a later attempt, even with the right password, until the window ends [NFR-SEC-01] (scenario 4b)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();
    foreach (range(1, 4) as $i) {
        $this->browser->post('/api/v1/auth/two-factor/setup', ['password' => 'not the password at all'])->assertStatus(422);
    }
    $this->browser->post('/api/v1/auth/two-factor/setup', ['password' => 'not the password at all'])->assertStatus(401);

    // The person signs in again: the settings routes answer 429, and nobody is signed out any more.
    $this->browser->login($user['email'], $user['password'])->assertOk();
    foreach (['setup', 'disable', 'recovery-codes'] as $route) {
        $response = $this->browser->post("/api/v1/auth/two-factor/{$route}", ['password' => $user['password'], 'code' => '123456']);
        $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
    }
    $this->browser->get('/api/v1/auth/me')->assertOk();

    // After the window, the right password works.
    $this->travel(16)->minutes();
    $this->browser->login($user['email'], $user['password'])->assertOk();
    $this->browser->post('/api/v1/auth/two-factor/setup', ['password' => $user['password']])->assertOk();
});

it('feeds the same counter from the owner\'s reset, and answers 429 there too [NFR-SEC-01] (scenario 3c)', function () {
    $t = Team::two();
    TwoFactor::enable($this, $t['a']['manager']);
    Team::signIn($this, $t['a']['owner']);
    guessAtLogin($this, $t['a']['owner'], 5);

    $this->browser->post("/api/v1/users/{$t['a']['manager']['user']}/two-factor/reset", ['password' => $t['a']['owner']['password']])
        ->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect(TwoFactor::row($t['a']['manager']['user'])['two_factor_confirmed_at'])->not->toBeNull();
    $this->browser->get('/api/v1/auth/me')->assertOk();
});

it('keeps one account\'s failures apart from another\'s [FR-INST-05]', function () {
    $a = Accounts::user();
    $b = Accounts::user();
    $session = new AuthClient($this);
    $session->login($b['email'], $b['password'])->assertOk();

    guessAtLogin($this, $a, 5);

    $session->post('/api/v1/auth/two-factor/setup', ['password' => $b['password']])->assertOk();
});
