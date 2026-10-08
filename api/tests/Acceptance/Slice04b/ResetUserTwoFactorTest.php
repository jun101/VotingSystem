<?php

/*
 * DELETE /api/v1/users/{user}/two-factor — docs/api/users/DELETE-users-{user}-two-factor.md
 * Tenant suite, route api/v1/users/{user}/two-factor: another institution's user answers 404, the
 * same as an unknown one (scenario 5), and a manager of this institution is refused (scenario 6).
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Team;
use Tests\Support\Totp;
use Tests\Support\TwoFactor;

const RESET_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function resetUrl(string $uuid): string
{
    return "/api/v1/users/{$uuid}/two-factor";
}

it('turns off a manager\'s two-factor, who then signs in with the password only [FR-INST-04] (scenario 1)', function () {
    $t = Team::two();
    TwoFactor::enable($this, $t['a']['manager']);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->delete(resetUrl($t['a']['manager']['user']))->assertNoContent();

    $row = TwoFactor::row($t['a']['manager']['user']);
    expect($row['two_factor_secret'])->toBeNull()
        ->and($row['two_factor_recovery_codes'])->toBeNull()
        ->and($row['two_factor_confirmed_at'])->toBeNull()
        ->and($row['two_factor_last_step'])->toBeNull();
    (new AuthClient($this))->login($t['a']['manager']['email'], $t['a']['manager']['password'])->assertOk()
        ->assertJsonPath('data.id', $t['a']['manager']['user']);
});

it('keeps the open sessions of the user whose two-factor was turned off [FR-INST-04] (scenario 1)', function () {
    $t = Team::two();
    $two = TwoFactor::enable($this, $t['a']['manager']);
    $manager = TwoFactor::pending($this, $t['a']['manager'], new AuthClient($this));
    $manager->post('/api/v1/auth/two-factor-challenge', ['code' => Totp::code($two['secret'])])->assertOk();
    Team::signIn($this, $t['a']['owner']);

    $this->browser->delete(resetUrl($t['a']['manager']['user']))->assertNoContent();

    $manager->get('/api/v1/auth/me')->assertOk();
});

it('turns off another owner\'s two-factor [FR-INST-04] (scenario 2)', function () {
    $t = Team::two();
    TwoFactor::enable($this, $t['a']['owner2']);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->delete(resetUrl($t['a']['owner2']['user']))->assertNoContent();

    expect(TwoFactor::row($t['a']['owner2']['user'])['two_factor_confirmed_at'])->toBeNull();
});

it('refuses to turn off one\'s own, owner or not, with or without two-factor [FR-INST-04] (scenario 3)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $this->browser->delete(resetUrl($t['a']['owner']['user']))->assertStatus(409)->assertJsonPath('error.code', 'cannot_reset_self');

    $two = TwoFactor::enable($this, $t['a']['owner2']);
    $second = TwoFactor::pending($this, $t['a']['owner2'], new AuthClient($this));
    $second->post('/api/v1/auth/two-factor-challenge', ['code' => Totp::code($two['secret'])])->assertOk();
    $second->delete(resetUrl($t['a']['owner2']['user']))->assertStatus(409)->assertJsonPath('error.code', 'cannot_reset_self');
    expect(TwoFactor::row($t['a']['owner2']['user'])['two_factor_confirmed_at'])->not->toBeNull();
});

it('answers 409 when the user has no two-factor [FR-INST-04] (scenario 4)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $this->browser->delete(resetUrl($t['a']['manager']['user']))->assertStatus(409)->assertJsonPath('error.code', 'two_factor_not_enabled');
});

it('answers 404, the same for every case, for a user that is unknown, removed, of another institution or a platform admin [FR-INST-05] (scenario 5)', function () {
    $t = Team::two();
    $removed = Accounts::user(['role' => 'manager', 'institution' => $t['a']['owner']['institution'], 'removed' => true]);
    $admin = Accounts::user(['role' => 'platform_admin']);
    TwoFactor::enable($this, $t['b']['manager']);
    TwoFactor::enable($this, $admin);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->delete(resetUrl(RESET_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$t['b']['manager']['user'], $t['b']['owner']['user'], $removed['user'], $admin['user'], 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->delete(resetUrl($target))))->toBe($unknown);
    }

    // Nothing of the other institution or of the platform admin changed.
    expect(TwoFactor::row($t['b']['manager']['user'])['two_factor_confirmed_at'])->not->toBeNull()
        ->and(TwoFactor::row($admin['user'])['two_factor_confirmed_at'])->not->toBeNull();
});

it('answers 403 to a manager and changes nothing, and 404 first for another institution\'s user [FR-INST-03] (scenario 6)', function () {
    $t = Team::two();
    TwoFactor::enable($this, $t['a']['owner2']);
    TwoFactor::enable($this, $t['b']['owner']);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->delete(resetUrl($t['a']['owner2']['user']))->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    expect(TwoFactor::row($t['a']['owner2']['user'])['two_factor_confirmed_at'])->not->toBeNull();

    $this->browser->delete(resetUrl($t['b']['owner']['user']))->assertStatus(404);
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-04] (scenario 7)', function () {
    $t = Team::two();
    TwoFactor::enable($this, $t['a']['manager']);

    $this->browser->delete(resetUrl($t['a']['manager']['user']))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(TwoFactor::row($t['a']['manager']['user'])['two_factor_confirmed_at'])->not->toBeNull();
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 8)', function () {
    $t = Team::two();
    TwoFactor::enable($this, $t['a']['manager']);
    Team::signIn($this, $t['a']['owner']);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->delete(resetUrl($t['a']['manager']['user']))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(TwoFactor::row($t['a']['manager']['user'])['two_factor_confirmed_at'])->not->toBeNull();
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 9)', function () {
    $t = Team::two();
    TwoFactor::enable($this, $t['a']['manager']);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->delete(resetUrl($t['a']['manager']['user']), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(TwoFactor::row($t['a']['manager']['user'])['two_factor_confirmed_at'])->not->toBeNull();
});

it('answers 405 to another method than DELETE [NFR-SEC-01] (scenario 10)', function (string $method) {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->other($method, resetUrl($t['a']['manager']['user']));

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect(array_map('trim', explode(',', (string) $response->headers->get('Allow'))))->toContain('DELETE');
})->with(['GET', 'POST', 'PUT', 'PATCH']);
