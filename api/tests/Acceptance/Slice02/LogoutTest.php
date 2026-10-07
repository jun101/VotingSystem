<?php

/*
 * POST /api/v1/auth/logout — docs/api/auth/POST-auth-logout.md
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;

const LOGOUT = '/api/v1/auth/logout';

it('ends the session, and the old cookie opens nothing [FR-INST-04] (scenario 1)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $response = $this->browser->post(LOGOUT);

    $response->assertNoContent();
    expect($response->getContent())->toBe('');

    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
});

it('does not honour the session cookie it ended [FR-INST-04] (scenario 1)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $oldSession = $this->browser->cookie(config('session.cookie'));
    $this->browser->post(LOGOUT)->assertNoContent();

    $thief = new AuthClient($this);
    $reflection = new ReflectionProperty($thief, 'cookies');
    $reflection->setValue($thief, [config('session.cookie') => $oldSession]);

    $thief->get('/api/v1/auth/me')->assertStatus(401);
});

it('answers 401 when nobody is signed in [FR-INST-04] (scenario 2)', function () {
    $this->browser->post(LOGOUT)
        ->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 419 when the CSRF token is missing and keeps the session [NFR-SEC-04] (scenario 3)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $this->browser->post(LOGOUT, [], [], csrf: false)
        ->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');

    $this->browser->get('/api/v1/auth/me')->assertOk();
});

it('answers 405 to another method than POST [NFR-SEC-01] (scenario 4)', function () {
    $response = $this->browser->other('GET', LOGOUT);

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect($response->headers->get('Allow'))->toContain('POST');
});
