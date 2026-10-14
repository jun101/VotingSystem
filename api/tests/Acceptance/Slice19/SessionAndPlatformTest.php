<?php

/*
 * Absolute session lifetime (finding S9 of slice 02) and two-factor for the platform admin, docs/slices/19-security-hardening.md "Rules".
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Team;
use Tests\Support\Totp;
use Tests\Support\TwoFactor;

it('ends a session 12 hours after sign-in however active it is [NFR-SEC-01]', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    foreach (range(1, 5) as $hour) {
        $this->travel(2)->hours();
        $this->browser->get('/api/v1/auth/me')->assertOk();
    }
    $this->travel(2)->hours();

    $this->browser->get('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('counts the 12 hours from the second step of a two-factor sign-in [NFR-SEC-01]', function () {
    $t = Team::two();
    $two = TwoFactor::enable($this, $t['a']['owner']);
    TwoFactor::clearLastStep($t['a']['owner']['user']);
    $browser = new AuthClient($this);
    $browser->login($t['a']['owner']['email'], $t['a']['owner']['password'])->assertOk()->assertJsonPath('data.two_factor_required', true);
    $this->travel(3)->minutes();
    $browser->post('/api/v1/auth/two-factor-challenge', ['code' => Totp::code($two['secret'], Totp::step(now()->getTimestamp()))])->assertOk();

    foreach (range(1, 5) as $hop) {
        $this->travel(2)->hours();
        $browser->get('/api/v1/auth/me')->assertOk();
    }
    $this->travel(2)->hours();

    $browser->get('/api/v1/auth/me')->assertStatus(401);
});

it('refuses /platform without two-factor when the rule is on, and keeps the setup routes [NFR-SEC-01]', function () {
    config(['platform.require_two_factor' => true]);
    $admin = Accounts::user(['role' => 'platform_admin', 'name' => 'Admin Plateforme', 'email' => 'admin@platform.test']);
    Team::signIn($this, $admin);

    $this->browser->get('/api/v1/platform/institutions')->assertStatus(403)->assertJsonPath('error.code', 'two_factor_required');
    $this->browser->post('/api/v1/platform/institutions/'.$admin['user'].'/suspend', ['reason' => 'Motif valide'])->assertStatus(403)->assertJsonPath('error.code', 'two_factor_required');
    $this->browser->get('/api/v1/auth/me')->assertOk()->assertJsonPath('data.role', 'platform_admin');
    $this->browser->post('/api/v1/auth/two-factor/setup', ['password' => $admin['password']])->assertOk();
});

it('lets the platform admin in once two-factor is on [NFR-SEC-01]', function () {
    config(['platform.require_two_factor' => true]);
    $admin = Accounts::user(['role' => 'platform_admin', 'name' => 'Admin Plateforme', 'email' => 'admin@platform.test']);
    $two = TwoFactor::enable($this, $admin);
    TwoFactor::clearLastStep($admin['user']);
    $browser = new AuthClient($this);
    $browser->login($admin['email'], $admin['password'])->assertOk()->assertJsonPath('data.two_factor_required', true);
    $browser->post('/api/v1/auth/two-factor-challenge', ['code' => Totp::code($two['secret'])])->assertOk();

    $browser->get('/api/v1/platform/institutions')->assertOk();
});

it('does not ask owners for it [NFR-SEC-01]', function () {
    config(['platform.require_two_factor' => true]);
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $this->browser->get('/api/v1/elections')->assertOk();
});
