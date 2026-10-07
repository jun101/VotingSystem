<?php

/*
 * POST /api/v1/auth/reset-password — docs/api/auth/POST-auth-reset-password.md
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;

const RESET = '/api/v1/auth/reset-password';
const NEW_PASSWORD = 'un nouveau mot de passe long';

function userWithResetToken(array $options = []): array
{
    $user = Accounts::user($options);
    $user['token'] = Accounts::token();
    Accounts::plantToken('password_reset_tokens', $user['email'], $user['token']);

    return $user;
}

it('sets the new password, spends the token and ends the other sessions [FR-INST-04] (scenario 1)', function () {
    $user = userWithResetToken();

    // The user is signed in on another device.
    $device = new AuthClient($this);
    $device->login($user['email'], $user['password'])->assertOk();
    $device->get('/api/v1/auth/me')->assertOk();

    $response = $this->browser->post(RESET, ['token' => $user['token'], 'password' => NEW_PASSWORD]);

    $response->assertNoContent();
    expect($response->getContent())->toBe('')
        ->and(Accounts::userRow($user['email'])['password'])->toStartWith('$argon2id$')
        ->and(Accounts::tokenRows('password_reset_tokens', $user['email']))->toBe([]);

    // Not signed in by this call; the other device is signed out.
    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
    $device->get('/api/v1/auth/me')->assertStatus(401);

    // The new password works, the old one does not.
    (new AuthClient($this))->login($user['email'], $user['password'])->assertStatus(401);
    (new AuthClient($this))->login($user['email'], NEW_PASSWORD)->assertOk();
});

it('marks an email that was not verified as verified [FR-INST-04] (scenario 1)', function () {
    $user = userWithResetToken(['verified' => false]);

    $this->browser->post(RESET, ['token' => $user['token'], 'password' => NEW_PASSWORD])->assertNoContent();

    expect(Accounts::userRow($user['email'])['email_verified_at'])->not->toBeNull();
});

it('answers 422 when a field is missing [FR-INST-04] (scenario 2)', function () {
    $user = userWithResetToken();

    foreach (['token', 'password'] as $field) {
        $body = ['token' => $user['token'], 'password' => NEW_PASSWORD];
        unset($body[$field]);

        $response = $this->browser->post(RESET, $body);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        expect($response->json("error.fields.{$field}"))->toContain('required');
    }
});

it('answers 422 for a password shorter than 12 characters and keeps the link usable [NFR-SEC-02] (scenario 3)', function () {
    $user = userWithResetToken();

    $response = $this->browser->post(RESET, ['token' => $user['token'], 'password' => 'elevenchars']);

    $response->assertStatus(422);
    expect($response->json('error.fields.password'))->toContain('min')
        ->and(Accounts::tokenRows('password_reset_tokens', $user['email']))->toHaveCount(1);

    $this->browser->post(RESET, ['token' => $user['token'], 'password' => NEW_PASSWORD])->assertNoContent();
});

it('answers 422 for a password equal to the email [NFR-SEC-02] (scenario 4)', function () {
    $user = userWithResetToken(['email' => 'marie.joseph@example.test']);

    $response = $this->browser->post(RESET, ['token' => $user['token'], 'password' => 'marie.joseph@example.test']);

    $response->assertStatus(422);
    expect($response->json('error.fields.password'))->toContain('same_as_email');
});

it('answers 422 for a token that is unknown, already used or replaced [FR-INST-04] (scenario 5)', function () {
    $user = userWithResetToken();

    foreach ([Accounts::token(), 'abc'] as $unknown) {
        $response = $this->browser->post(RESET, ['token' => $unknown, 'password' => NEW_PASSWORD]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        expect($response->json('error.fields.token'))->toContain('invalid');
    }

    // Used once, not twice.
    $this->browser->post(RESET, ['token' => $user['token'], 'password' => NEW_PASSWORD])->assertNoContent();
    $this->browser->post(RESET, ['token' => $user['token'], 'password' => 'another long password'])->assertStatus(422);
    (new AuthClient($this))->login($user['email'], NEW_PASSWORD)->assertOk();

    // Replaced by a newer one.
    $other = userWithResetToken();
    $new = Accounts::token();
    Accounts::plantToken('password_reset_tokens', $other['email'], $new);

    $this->browser->post(RESET, ['token' => $other['token'], 'password' => NEW_PASSWORD])->assertStatus(422);
    $this->browser->post(RESET, ['token' => $new, 'password' => NEW_PASSWORD])->assertNoContent();
});

it('answers 410 for a token older than 60 minutes and changes nothing [FR-INST-04] (scenario 6)', function () {
    $user = Accounts::user();
    $token = Accounts::token();
    Accounts::plantToken('password_reset_tokens', $user['email'], $token, now('UTC')->subMinute());

    $response = $this->browser->post(RESET, ['token' => $token, 'password' => NEW_PASSWORD]);

    $response->assertStatus(410)->assertJsonPath('error.code', 'expired');
    (new AuthClient($this))->login($user['email'], $user['password'])->assertOk();
});

it('answers 419 when the CSRF token is missing [NFR-SEC-04] (scenario 7)', function () {
    $user = userWithResetToken();

    $this->browser->post(RESET, ['token' => $user['token'], 'password' => NEW_PASSWORD], [], csrf: false)
        ->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Accounts::tokenRows('password_reset_tokens', $user['email']))->toHaveCount(1);
});

it('answers 429 above 10 requests an hour from one address [NFR-SEC-05] (scenario 8)', function () {
    foreach (range(1, 10) as $i) {
        $this->browser->post(RESET, ['token' => Accounts::token(), 'password' => NEW_PASSWORD])->assertStatus(422);
    }

    $this->browser->post(RESET, ['token' => Accounts::token(), 'password' => NEW_PASSWORD])
        ->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});

it('answers 405 to another method than POST [NFR-SEC-01] (scenario 9)', function () {
    $response = $this->browser->other('GET', RESET);

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect($response->headers->get('Allow'))->toContain('POST');
});
