<?php

/*
 * PATCH /api/v1/auth/me — docs/api/auth/PATCH-auth-me.md
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;

const PATCH_ME = '/api/v1/auth/me';

function storedLanguage(string $userUuid): string
{
    return (string) DB::connection(useMigratorConnection())->table('users')->where('uuid', $userUuid)->value('language');
}

it('changes the language of the signed-in user [NFR-UX-01] (scenario 1)', function () {
    $user = Accounts::user(['language' => 'fr']);
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $response = $this->browser->patch(PATCH_ME, ['language' => 'en']);

    $response->assertOk();
    expect(array_keys($response->json()))->toBe(['data'])
        ->and(array_keys($response->json('data')))->toEqualCanonicalizing(['id', 'name', 'email', 'role', 'email_verified', 'language', 'institution'])
        ->and($response->json('data.id'))->toBe($user['user'])
        ->and($response->json('data.language'))->toBe('en')
        ->and($response->json('data.institution.id'))->toBe($user['institution'])
        ->and(storedLanguage($user['user']))->toBe('en');

    // It is what GET /auth/me says from now on.
    $this->browser->get(PATCH_ME)->assertOk()->assertJsonPath('data.language', 'en');
});

it('answers 200 when the language is already the one asked [NFR-UX-01] (scenario 2)', function () {
    $user = Accounts::user(['language' => 'en']);
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $this->browser->patch(PATCH_ME, ['language' => 'en'])->assertOk()->assertJsonPath('data.language', 'en');
    expect(storedLanguage($user['user']))->toBe('en');
});

it('answers 422 when the language is missing [NFR-UX-01] (scenario 3)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $response = $this->browser->patch(PATCH_ME, []);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields'))->toBe(['language' => ['required']])
        ->and(storedLanguage($user['user']))->toBe('fr');
});

it('answers 422 when the language is not fr or en [NFR-UX-01] (scenario 4)', function (mixed $value) {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $response = $this->browser->patch(PATCH_ME, ['language' => $value]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields'))->toHaveKey('language')
        ->and(storedLanguage($user['user']))->toBe('fr');
})->with([['es'], ['FR'], [''], ['fr-HT'], [12], [['fr']], [null]]);

it('answers 401 when nobody is signed in or the session has gone [NFR-UX-01] (scenario 5)', function () {
    $response = $this->browser->patch(PATCH_ME, ['language' => 'en']);

    $response->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message']);

    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();
    $this->browser->forgetCookie(config('session.cookie'));
    $this->browser->patch(PATCH_ME, ['language' => 'en'])->assertStatus(401);
    expect(storedLanguage($user['user']))->toBe('fr');
});

it('answers 403 and changes nothing when the institution was suspended since sign-in [FR-INST-06] (scenario 6)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();

    DB::connection(useMigratorConnection())->table('institutions')
        ->where('uuid', $user['institution'])->update(['suspended_at' => now('UTC')->format('Y-m-d H:i:s')]);

    $this->browser->patch(PATCH_ME, ['language' => 'en'])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(storedLanguage($user['user']))->toBe('fr');
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 7)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $this->browser->patch(PATCH_ME, ['language' => 'en'], [], false)
        ->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->patch(PATCH_ME, ['language' => 'en'], ['X-XSRF-TOKEN' => 'not-the-token'], false)
        ->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(storedLanguage($user['user']))->toBe('fr');
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 8)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $this->browser->rawBody('PATCH', PATCH_ME, '{"language": "en"')
        ->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
    expect(storedLanguage($user['user']))->toBe('fr');
});

it('answers 405 to another method than GET, HEAD or PATCH [NFR-SEC-01] (scenario 9)', function (string $method) {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $response = $this->browser->other($method, PATCH_ME);

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $allow = array_map('trim', explode(',', (string) $response->headers->get('Allow')));
    expect($allow)->toContain('GET')->toContain('HEAD')->toContain('PATCH')->not->toContain($method);
})->with(['POST', 'PUT', 'DELETE']);

it('changes only the language, whatever else the body says [FR-INST-05] (notes)', function () {
    $user = Accounts::user(['role' => 'manager']);
    $other = Accounts::user(['language' => 'fr']);
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $before = Accounts::userRow($user['email']);

    $this->browser->patch(PATCH_ME, [
        'language' => 'en',
        'role' => 'platform_admin',
        'email' => 'taken-over@example.test',
        'name' => 'Someone Else',
        'institution_id' => 1,
        'institution' => $other['institution'],
        'id' => $other['user'],
        'user' => $other['user'],
    ])->assertOk()->assertJsonPath('data.id', $user['user'])->assertJsonPath('data.role', 'manager');

    $after = Accounts::userRow($user['email']);
    unset($before['updated_at'], $after['updated_at']);

    expect($after['language'])->toBe('en')
        ->and(array_diff_assoc($after, $before))->toBe(['language' => 'en'])
        ->and(storedLanguage($other['user']))->toBe('fr');
});

it('works for a platform admin, who has no institution [NFR-UX-01] (caller)', function () {
    $admin = Accounts::user(['role' => 'platform_admin', 'language' => 'fr']);
    $this->browser->login($admin['email'], $admin['password'])->assertOk();

    $this->browser->patch(PATCH_ME, ['language' => 'en'])
        ->assertOk()->assertJsonPath('data.language', 'en')->assertJsonPath('data.institution', null);
});

it('applies to the emails sent to this user from then on [NFR-UX-01] (notes)', function () {
    $user = Accounts::user(['verified' => false, 'language' => 'fr']);
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $this->browser->patch(PATCH_ME, ['language' => 'en'])->assertOk();
    $this->browser->post('/api/v1/auth/verify-email/resend')->assertNoContent();

    $mails = Accounts::mailTo($user['email']);
    expect($mails)->toHaveCount(1)
        ->and(mb_strtolower($mails[0]['subject']))->toContain('verify');
});

it('does not touch the other users of the same or another institution [FR-INST-05] (notes)', function () {
    $owner = Accounts::user(['language' => 'fr']);
    $colleague = Accounts::user(['role' => 'manager', 'institution' => $owner['institution'], 'language' => 'fr']);
    $stranger = Accounts::user(['language' => 'fr']);

    $this->browser->login($owner['email'], $owner['password'])->assertOk();
    $this->browser->patch(PATCH_ME, ['language' => 'en'])->assertOk();

    expect(storedLanguage($owner['user']))->toBe('en')
        ->and(storedLanguage($colleague['user']))->toBe('fr')
        ->and(storedLanguage($stranger['user']))->toBe('fr');
});

it('logs the request id and the outcome only [NFR-OPS-04] (notes)', function () {
    $lines = [];
    Illuminate\Support\Facades\Log::listen(function ($event) use (&$lines) {
        $lines[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    });

    $user = Accounts::user(['email' => 'quiet.person@example.test']);
    $this->browser->login($user['email'], $user['password'])->assertOk();
    $this->browser->patch(PATCH_ME, ['language' => 'en'])->assertOk();

    $log = implode("\n", $lines);
    foreach (['quiet.person@example.test', 'Marie Joseph', $user['password']] as $secret) {
        expect($log)->not->toContain($secret);
    }
});
