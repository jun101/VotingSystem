<?php

/*
 * POST /api/v1/auth/login, scenario 10 — docs/api/auth/POST-auth-login.md
 * The first step of signing in for a person who has two-factor authentication turned on.
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\TwoFactor;

it('does not sign in a user with two-factor: it asks for the second step [FR-INST-04] (scenario 10)', function () {
    $user = Accounts::user();
    TwoFactor::enable($this, $user);   // signs in once with the password alone, which sets last_login_at
    $before = TwoFactor::row($user['user'])['last_login_at'];

    $this->travel(2)->minutes();
    $response = $this->browser->login($user['email'], $user['password']);

    $response->assertOk();
    expect($response->json())->toBe(['data' => ['two_factor_required' => true]]);

    // Nothing is granted yet, and the time of the last sign-in has not moved.
    $this->browser->get('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    $this->browser->get('/api/v1/institution')->assertStatus(401);
    expect(TwoFactor::row($user['user'])['last_login_at'])->toBe($before);
});

it('signs in as before a user who has no two-factor [FR-INST-04] (scenario 1)', function () {
    $user = Accounts::user();

    $response = $this->browser->login($user['email'], $user['password'])->assertOk();

    expect($response->json('data.id'))->toBe($user['user'])
        ->and($response->json('data'))->not->toHaveKey('two_factor_required');
    $this->browser->get('/api/v1/auth/me')->assertOk();
});

it('treats a started but unconfirmed setup as no two-factor [FR-INST-04] (scenario 1)', function () {
    $user = Accounts::user();
    TwoFactor::plantUnconfirmed($user['user'], 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');

    $this->browser->login($user['email'], $user['password'])->assertOk()->assertJsonPath('data.id', $user['user']);
});

it('also asks the platform admin for the second step [FR-INST-04] (scenario 10)', function () {
    $admin = Accounts::user(['role' => 'platform_admin']);
    TwoFactor::enable($this, $admin);

    $this->browser->login($admin['email'], $admin['password'])->assertOk()->assertJsonPath('data.two_factor_required', true);
    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
});

it('answers a wrong password exactly as without two-factor, so the answer does not tell who has it [FR-INST-04] (scenario 4)', function () {
    $with = Accounts::user(['email' => 'with@example.test']);
    $without = Accounts::user(['email' => 'without@example.test']);
    TwoFactor::enable($this, $with);

    $a = (new AuthClient($this))->login('with@example.test', 'not the password at all');
    $b = (new AuthClient($this))->login('without@example.test', 'not the password at all');

    $a->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
    expect($a->json())->toBe($b->json());
});

it('still answers 403 for a suspended institution at the first step [FR-INST-06] (scenario 6)', function () {
    $user = Accounts::user();
    TwoFactor::enable($this, $user);
    Accounts::suspend($user['institution']);

    (new AuthClient($this))->login($user['email'], $user['password'])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('keeps the pending sign-in in the browser that gave the password, not in another [FR-INST-04] (scenario 10)', function () {
    $user = Accounts::user();
    $two = TwoFactor::enable($this, $user);
    TwoFactor::pending($this, $user, $this->browser);

    $other = new AuthClient($this);
    $other->post('/api/v1/auth/two-factor-challenge', ['code' => Tests\Support\Totp::code($two['secret'])])
        ->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('writes nothing to the log but the outcome, never a code or an address [FR-INST-04]', function () {
    $user = Accounts::user(['email' => 'quiet.owner@example.test']);
    TwoFactor::enable($this, $user);

    $this->browser->login($user['email'], $user['password'])->assertOk();

    foreach (glob(storage_path('logs/*.log')) ?: [] as $log) {
        expect(file_get_contents($log))->not->toContain('quiet.owner@example.test');
    }
});

it('regenerates the session id at the first step [NFR-SEC-04] (scenario 10)', function () {
    $user = Accounts::user();
    TwoFactor::enable($this, $user);
    $this->browser->csrf();
    $before = $this->browser->cookie(config('session.cookie'));

    $this->browser->login($user['email'], $user['password'])->assertOk()->assertJsonPath('data.two_factor_required', true);

    expect($this->browser->cookie(config('session.cookie')))->not->toBe($before);
});

it('signs out a user already signed in in this browser: only the pending sign-in remains [FR-INST-04] (scenario 10)', function () {
    $a = Accounts::user();
    $b = Accounts::user();
    TwoFactor::enable($this, $b);
    $this->browser->login($a['email'], $a['password'])->assertOk();
    $this->browser->get('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $a['user']);

    $this->browser->login($b['email'], $b['password'])->assertOk()->assertJsonPath('data.two_factor_required', true);

    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
});
