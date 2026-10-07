<?php

/*
 * POST /api/v1/auth/verify-email/resend — docs/api/auth/POST-auth-verify-email-resend.md
 */

use Tests\Support\Accounts;

const RESEND = '/api/v1/auth/verify-email/resend';

function signedInUnverified($test): array
{
    $user = Accounts::user(['verified' => false]);
    $test->browser->login($user['email'], $user['password'])->assertOk();

    return $user;
}

it('sends a new email and replaces the old link [FR-INST-01] (scenario 1)', function () {
    $user = signedInUnverified($this);
    $old = Accounts::token();
    Accounts::plantToken('email_verification_tokens', $user['email'], $old);

    $response = $this->browser->post(RESEND);

    $response->assertNoContent();
    expect($response->getContent())->toBe('');

    $mails = Accounts::mailTo($user['email']);
    expect($mails)->toHaveCount(1);

    $new = Accounts::tokenIn($mails[0], '/verify-email');
    expect($new)->not->toBeNull()->not->toBe($old)
        ->and(Accounts::tokenRows('email_verification_tokens', $user['email']))->toHaveCount(1);

    // The old link no longer works, the new one does.
    $this->browser->forgetCookies();
    $this->browser->post('/api/v1/auth/verify-email', ['token' => $old])->assertStatus(422);
    $this->browser->post('/api/v1/auth/verify-email', ['token' => $new])->assertNoContent();
});

it('writes the email in the language of the user [NFR-UX-01] (scenario 1)', function () {
    $user = Accounts::user(['verified' => false, 'language' => 'en']);
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $this->browser->post(RESEND)->assertNoContent();

    expect(mb_strtolower(Accounts::mailTo($user['email'])[0]['subject']))->toContain('verify');
});

it('answers 401 when nobody is signed in [FR-INST-01] (scenario 2)', function () {
    $response = $this->browser->post(RESEND);

    $response->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::mail())->toBe([]);
});

it('answers 409 when the email is already verified [FR-INST-01] (scenario 3)', function () {
    $user = Accounts::user(['verified' => true]);
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $response = $this->browser->post(RESEND);

    $response->assertStatus(409)->assertJsonPath('error.code', 'already_verified');
    expect(Accounts::mail())->toBe([]);
});

it('answers 419 when the CSRF token is missing [NFR-SEC-04] (scenario 4)', function () {
    signedInUnverified($this);

    $this->browser->post(RESEND, [], [], csrf: false)
        ->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Accounts::mail())->toBe([]);
});

it('answers 429 above 3 requests a minute from one user [NFR-SEC-05] (scenario 5)', function () {
    signedInUnverified($this);

    foreach (range(1, 3) as $i) {
        $this->browser->post(RESEND)->assertNoContent();
    }

    $response = $this->browser->post(RESEND);

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(Accounts::mail())->toHaveCount(3);
});

it('answers 405 to another method than POST [NFR-SEC-01] (scenario 6)', function () {
    $response = $this->browser->other('GET', RESEND);

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect($response->headers->get('Allow'))->toContain('POST');
});
