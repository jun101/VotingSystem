<?php

/*
 * POST /api/v1/auth/forgot-password — docs/api/auth/POST-auth-forgot-password.md
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;

const FORGOT = '/api/v1/auth/forgot-password';

it('sends a reset link to an existing user and replaces the old one [FR-INST-04] (scenario 1)', function () {
    $user = Accounts::user();
    $old = Accounts::token();
    Accounts::plantToken('password_reset_tokens', $user['email'], $old);

    $response = $this->browser->post(FORGOT, ['email' => $user['email']]);

    $response->assertNoContent();
    expect($response->getContent())->toBe('');

    $mails = Accounts::mailTo($user['email']);
    expect($mails)->toHaveCount(1);

    $token = Accounts::tokenIn($mails[0], '/reset-password');
    expect($token)->not->toBeNull()->not->toBe($old);

    $rows = Accounts::tokenRows('password_reset_tokens', $user['email']);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['token_hash'])->toBe(hash('sha256', $token, true));

    $minutes = \Illuminate\Support\Carbon::parse($rows[0]['expires_at'], 'UTC')->diffInMinutes(now('UTC'), true);
    expect($minutes)->toBeBetween(58, 60.1);

    // The link carries the token only: no address, no id.
    $body = $mails[0]['text'].$mails[0]['html'];
    expect($body)->not->toContain('email=')->not->toContain(rawurlencode($user['email']))->not->toContain($user['user']);
});

it('writes the email in the language of the user [NFR-UX-01] (scenario 1)', function () {
    $fr = Accounts::user(['language' => 'fr']);
    $en = Accounts::user(['language' => 'en']);

    $this->browser->post(FORGOT, ['email' => $fr['email']])->assertNoContent();
    $this->browser->post(FORGOT, ['email' => $en['email']])->assertNoContent();

    $subjectFr = mb_strtolower(Accounts::mailTo($fr['email'])[0]['subject']);
    $subjectEn = mb_strtolower(Accounts::mailTo($en['email'])[0]['subject']);

    expect($subjectFr)->not->toBe($subjectEn)->and($subjectEn)->toContain('reset');
});

it('answers the same for an address that belongs to nobody, and sends and writes nothing [FR-INST-04] (scenario 2)', function () {
    $user = Accounts::user();

    $known = $this->browser->post(FORGOT, ['email' => $user['email']]);
    $unknown = (new AuthClient($this))->post(FORGOT, ['email' => 'nobody@example.test']);

    $unknown->assertNoContent();
    expect($unknown->getContent())->toBe($known->getContent())
        ->and($unknown->getStatusCode())->toBe($known->getStatusCode())
        ->and(Accounts::mailTo('nobody@example.test'))->toBe([])
        ->and(Accounts::mail())->toHaveCount(1)
        ->and(\Illuminate\Support\Facades\DB::connection(useMigratorConnection())->table('password_reset_tokens')->count())->toBe(1);
});

it('treats a removed user as nobody [FR-INST-04] (scenario 2)', function () {
    $removed = Accounts::user(['removed' => true]);

    $this->browser->post(FORGOT, ['email' => $removed['email']])->assertNoContent();

    expect(Accounts::mail())->toBe([]);
});

it('answers 422 when the email is missing or not an address [FR-INST-04] (scenario 3)', function () {
    foreach ([[], ['email' => 'nobody'], ['email' => '']] as $body) {
        $response = $this->browser->post(FORGOT, $body);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        expect($response->json('error.fields'))->toHaveKey('email');
    }
});

it('answers 419 when the CSRF token is missing [NFR-SEC-04] (scenario 4)', function () {
    $user = Accounts::user();

    $this->browser->post(FORGOT, ['email' => $user['email']], [], csrf: false)
        ->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Accounts::mail())->toBe([]);
});

it('answers 429 above 5 requests an hour from one address [NFR-SEC-05] (scenario 5)', function () {
    foreach (range(1, 5) as $i) {
        $this->browser->post(FORGOT, ['email' => "nobody{$i}@example.test"])->assertNoContent();
    }

    $response = $this->browser->post(FORGOT, ['email' => 'nobody6@example.test']);

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('answers 429 above 3 requests an hour for one email, an unknown one as well [NFR-SEC-05] (scenario 5)', function () {
    $user = Accounts::user();

    foreach ([$user['email'], 'nobody@example.test'] as $email) {
        foreach (range(1, 3) as $i) {
            (new AuthClient($this))->fromAddress('203.0.113.'.random_int(1, 250))->post(FORGOT, ['email' => $email])->assertNoContent();
        }

        $response = (new AuthClient($this))->fromAddress('203.0.113.'.random_int(1, 250))->post(FORGOT, ['email' => $email]);
        $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    }
});

it('answers 405 to another method than POST [NFR-SEC-01] (scenario 6)', function () {
    $response = $this->browser->other('GET', FORGOT);

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect($response->headers->get('Allow'))->toContain('POST');
});
