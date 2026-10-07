<?php

/*
 * GET /api/v1/auth/me — docs/api/auth/GET-auth-me.md
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;

const ME = '/api/v1/auth/me';

it('answers the signed-in user and their institution [FR-INST-01] (scenario 1)', function () {
    $user = Accounts::user(['role' => 'manager', 'language' => 'en']);
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $response = $this->browser->get(ME);

    $response->assertOk();
    $data = $response->json('data');

    expect(array_keys($response->json()))->toBe(['data'])
        ->and(array_keys($data))->toEqualCanonicalizing(['id', 'name', 'email', 'role', 'email_verified', 'language', 'institution'])
        ->and($data['id'])->toBe($user['user'])
        ->and($data['role'])->toBe('manager')
        ->and($data['language'])->toBe('en')
        ->and($data['email_verified'])->toBeTrue()
        ->and($data['institution']['id'])->toBe($user['institution'])
        ->and(array_keys($data['institution']))->toEqualCanonicalizing(['id', 'name', 'type'])
        ->and($response->getContent())->not->toContain('argon')->not->toContain('two_factor')->not->toContain('remember');
});

it('answers only about the user of the session, whatever the request [NFR-SEC-03] (scenario 1)', function () {
    $first = Accounts::user();
    $second = Accounts::user();

    $this->browser->login($first['email'], $first['password'])->assertOk();

    $this->browser->get(ME.'?id='.$second['user'].'&email='.$second['email'])
        ->assertOk()->assertJsonPath('data.id', $first['user']);
    $this->browser->get(ME, ['X-User' => $second['user'], 'Authorization' => 'Bearer '.$second['user']])
        ->assertOk()->assertJsonPath('data.id', $first['user']);
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-04] (scenario 2)', function () {
    $response = $this->browser->get(ME);

    $response->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(array_keys($response->json()))->toBe(['error'])
        ->and(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message']);

    // A session cookie the server does not know.
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();
    $this->browser->forgetCookie(config('session.cookie'));
    $this->browser->get(ME)->assertStatus(401);
});

it('answers 403 and ends the session when the institution was suspended since sign-in [FR-INST-06] (scenario 3)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();

    DB::connection(useMigratorConnection())->table('institutions')
        ->where('uuid', $user['institution'])->update(['suspended_at' => now('UTC')->format('Y-m-d H:i:s')]);

    $this->browser->get(ME)->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    $this->browser->get(ME)->assertStatus(401);
});

it('answers 405 to another method than GET or HEAD [NFR-SEC-01] (scenario 4)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $response = $this->browser->other('POST', ME);

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect($response->headers->get('Allow'))->toContain('GET');
});

it('does not keep the user of one request for the next one on the same worker [NFR-SEC-03] (architecture 4.2)', function () {
    $user = Accounts::user();
    $this->browser->login($user['email'], $user['password'])->assertOk();
    $this->browser->get(ME)->assertOk();

    // Another browser, with no cookie, on the same application instance: nobody.
    (new AuthClient($this))->get(ME)->assertStatus(401);
    (new AuthClient($this))->post('/api/v1/auth/verify-email/resend')->assertStatus(401);
});
