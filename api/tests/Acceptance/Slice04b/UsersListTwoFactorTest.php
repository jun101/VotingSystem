<?php

/*
 * GET /api/v1/users gains `two_factor_enabled` — docs/api/users/GET-users.md
 * Tenant suite note: another institution's users are never listed (see Slice04/ListUsersTest.php).
 */

use Tests\Support\Team;
use Tests\Support\TwoFactor;

it('says for each user whether their two-factor is on [FR-INST-04] (GET /users)', function () {
    $t = Team::two();
    TwoFactor::enable($this, $t['a']['manager']);
    TwoFactor::plantUnconfirmed($t['a']['owner2']['user'], 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->get('/api/v1/users')->assertOk();

    $byEmail = [];
    foreach ($response->json('data') as $user) {
        $byEmail[$user['email']] = $user['two_factor_enabled'];
    }
    expect($byEmail)->toBe([
        'alice.a@example.test' => false,
        'aline.a@example.test' => false,     // a setup that was never confirmed is not "on"
        'armand.a@example.test' => true,
    ]);
    expect($response->getContent())->not->toContain('two_factor_secret')->not->toContain('recovery');
});

it('shows it off again after the owner reset it [FR-INST-04] (GET /users)', function () {
    $t = Team::two();
    TwoFactor::enable($this, $t['a']['manager']);
    Team::signIn($this, $t['a']['owner']);
    $this->browser->post("/api/v1/users/{$t['a']['manager']['user']}/two-factor/reset", ['password' => $t['a']['owner']['password']])->assertNoContent();

    $users = $this->browser->get('/api/v1/users')->assertOk()->json('data');

    expect(array_column($users, 'two_factor_enabled', 'email')['armand.a@example.test'])->toBeFalse();
});
