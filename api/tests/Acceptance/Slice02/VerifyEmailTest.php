<?php

/*
 * POST /api/v1/auth/verify-email — docs/api/auth/POST-auth-verify-email.md
 */

use Illuminate\Support\Carbon;
use Tests\Support\Accounts;

const VERIFY = '/api/v1/auth/verify-email';

it('verifies the email and spends the token, with no session needed [FR-INST-01] (scenario 1)', function () {
    $user = Accounts::user(['verified' => false]);
    $token = Accounts::token();
    Accounts::plantToken('email_verification_tokens', $user['email'], $token);

    $response = $this->browser->post(VERIFY, ['token' => $token]);

    $response->assertNoContent();
    expect($response->getContent())->toBe('')
        ->and(Accounts::userRow($user['email'])['email_verified_at'])->not->toBeNull()
        ->and(Carbon::parse(Accounts::userRow($user['email'])['email_verified_at'], 'UTC')->diffInSeconds(now('UTC'), true))->toBeLessThan(60)
        ->and(Accounts::tokenRows('email_verification_tokens', $user['email']))->toBe([]);

    // The call signs nobody in.
    $this->browser->get('/api/v1/auth/me')->assertStatus(401);

    // The person signs in later and the email is verified.
    $this->browser->login($user['email'], $user['password'])->assertOk()->assertJsonPath('data.email_verified', true);
});

it('answers 422 when the token is missing [FR-INST-01] (scenario 2)', function () {
    $response = $this->browser->post(VERIFY, []);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.token'))->toContain('required');
});

it('answers 422 for a token that is unknown, already used or replaced [FR-INST-01] (scenario 3)', function () {
    $user = Accounts::user(['verified' => false]);

    // Unknown, and not even the right shape.
    foreach ([Accounts::token(), 'abc', str_repeat('z', 64)] as $unknown) {
        $response = $this->browser->post(VERIFY, ['token' => $unknown]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        expect($response->json('error.fields.token'))->toContain('invalid');
    }

    // Used once, not twice.
    $token = Accounts::token();
    Accounts::plantToken('email_verification_tokens', $user['email'], $token);
    $this->browser->post(VERIFY, ['token' => $token])->assertNoContent();
    $this->browser->post(VERIFY, ['token' => $token])->assertStatus(422);

    // Replaced by a newer one.
    $other = Accounts::user(['verified' => false, 'email' => 'other@example.test']);
    $old = Accounts::token();
    $new = Accounts::token();
    Accounts::plantToken('email_verification_tokens', $other['email'], $old);
    Accounts::plantToken('email_verification_tokens', $other['email'], $new);

    $this->browser->post(VERIFY, ['token' => $old])->assertStatus(422);
    expect(Accounts::userRow($other['email'])['email_verified_at'])->toBeNull();
    $this->browser->post(VERIFY, ['token' => $new])->assertNoContent();
});

it('answers 410 for a token older than 24 hours and keeps it [FR-INST-01] (scenario 4)', function () {
    $user = Accounts::user(['verified' => false]);
    $token = Accounts::token();
    Accounts::plantToken('email_verification_tokens', $user['email'], $token, now('UTC')->subMinute());

    $response = $this->browser->post(VERIFY, ['token' => $token]);

    $response->assertStatus(410)->assertJsonPath('error.code', 'expired');
    expect(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message'])
        ->and(Accounts::userRow($user['email'])['email_verified_at'])->toBeNull()
        ->and(Accounts::tokenRows('email_verification_tokens', $user['email']))->toHaveCount(1);
});

it('answers 419 when the CSRF token is missing [NFR-SEC-04] (scenario 5)', function () {
    $user = Accounts::user(['verified' => false]);
    $token = Accounts::token();
    Accounts::plantToken('email_verification_tokens', $user['email'], $token);

    $this->browser->post(VERIFY, ['token' => $token], [], csrf: false)
        ->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');

    expect(Accounts::userRow($user['email'])['email_verified_at'])->toBeNull();
});

it('answers 429 above 10 requests a minute from one address [NFR-SEC-05] (scenario 6)', function () {
    foreach (range(1, 10) as $i) {
        $this->browser->post(VERIFY, ['token' => Accounts::token()])->assertStatus(422);
    }

    $this->browser->post(VERIFY, ['token' => Accounts::token()])
        ->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});

it('answers 405 to another method than POST [NFR-SEC-01] (scenario 7)', function () {
    $response = $this->browser->other('GET', VERIFY);

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect($response->headers->get('Allow'))->toContain('POST');
});
