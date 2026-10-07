<?php

/*
 * POST /api/v1/auth/login — docs/api/auth/POST-auth-login.md
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;

const LOGIN = '/api/v1/auth/login';

it('signs the user in, verified or not, and rotates the session [FR-INST-04] (scenario 1)', function () {
    foreach ([true, false] as $verified) {
        $user = Accounts::user(['verified' => $verified]);
        $browser = new AuthClient($this);

        // The session exists before sign-in (the CSRF call starts it).
        $browser->csrf();
        $before = $browser->cookie(config('session.cookie'));

        $response = $browser->login($user['email'], $user['password']);

        $response->assertOk()
            ->assertJsonPath('data.id', $user['user'])
            ->assertJsonPath('data.email', $user['email'])
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.email_verified', $verified)
            ->assertJsonPath('data.institution.id', $user['institution']);

        expect(array_keys($response->json('data')))->toEqualCanonicalizing(['id', 'name', 'email', 'role', 'email_verified', 'language', 'institution'])
            ->and($browser->cookie(config('session.cookie')))->not->toBe($before)
            ->and($response->getContent())->not->toContain('argon')
            ->and(Accounts::userRow($user['email'])['last_login_at'])->not->toBeNull();

        $browser->get('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user['user']);
    }
});

it('accepts the email in another letter case [FR-INST-04] (scenario 1)', function () {
    $user = Accounts::user(['email' => 'marie@flamboyants.example']);

    $this->browser->login('MARIE@Flamboyants.Example', $user['password'])->assertOk();
});

it('answers 422 when the email or the password is missing [FR-INST-04] (scenario 2)', function () {
    $user = Accounts::user();

    foreach (['email', 'password'] as $field) {
        $body = ['email' => $user['email'], 'password' => $user['password']];
        unset($body[$field]);

        $response = $this->browser->post(LOGIN, $body);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        expect($response->json("error.fields.{$field}"))->toContain('required');
    }
});

it('gives the same 401 for an unknown email, a wrong password and a removed user [NFR-SEC-02] (scenarios 3, 4, 5)', function () {
    $user = Accounts::user();
    $removed = Accounts::user(['removed' => true]);

    $answers = [
        $this->browser->login('nobody@example.test', 'whatever it is, long enough'),
        $this->browser->login($user['email'], 'a wrong password, long enough'),
        $this->browser->login($removed['email'], $removed['password']),
    ];

    foreach ($answers as $answer) {
        $answer->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
    }

    expect($answers[1]->json())->toBe($answers[0]->json())
        ->and($answers[2]->json())->toBe($answers[0]->json())
        ->and(array_keys($answers[0]->json('error')))->toEqualCanonicalizing(['code', 'message']);

    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
});

it('takes about the same time for an unknown email as for a wrong password [NFR-SEC-02] (scenarios 3, 4)', function () {
    $user = Accounts::user();
    $this->browser->csrf();

    $time = function (string $email) use ($user) {
        $start = hrtime(true);
        $this->browser->login($email, 'a wrong password, long enough');

        return (hrtime(true) - $start) / 1e6;
    };

    // The password hash is checked against a dummy value when the user does not exist, so
    // the unknown email must not be much faster than the known one (milliseconds).
    $known = min($time($user['email']), $time($user['email']));
    $unknown = min($time('nobody1@example.test'), $time('nobody2@example.test'));

    expect($unknown)->toBeGreaterThan($known * 0.4);
});

it('answers 403 institution_suspended only when the password is right [FR-INST-06] (scenario 6)', function () {
    $user = Accounts::user(['suspended' => true]);

    $response = $this->browser->login($user['email'], $user['password']);

    $response->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    $this->browser->get('/api/v1/auth/me')->assertStatus(401);

    $this->browser->login($user['email'], 'a wrong password, long enough')
        ->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
});

it('answers 419 when the CSRF token is missing [NFR-SEC-04] (scenario 7)', function () {
    $user = Accounts::user();

    $this->browser->post(LOGIN, ['email' => $user['email'], 'password' => $user['password']], [], csrf: false)
        ->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
});

it('answers 429 after 5 failed attempts a minute for one email and one address [NFR-SEC-05] (scenario 8)', function () {
    $user = Accounts::user();

    foreach (range(1, 5) as $i) {
        $this->browser->login($user['email'], 'a wrong password, long enough')->assertStatus(401);
    }

    // The sixth is refused even with the right password.
    $response = $this->browser->login($user['email'], $user['password']);

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);

    // Another email from the same address is not locked by this.
    $other = Accounts::user();
    (new AuthClient($this))->login($other['email'], $other['password'])->assertOk();
});

it('answers 429 above 10 requests a minute from one address [NFR-SEC-05] (scenario 8)', function () {
    foreach (range(1, 10) as $i) {
        $this->browser->login("nobody{$i}@example.test", 'a wrong password, long enough')->assertStatus(401);
    }

    $this->browser->login('nobody11@example.test', 'a wrong password, long enough')
        ->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});

it('answers 405 to another method than POST [NFR-SEC-01] (scenario 9)', function () {
    $response = $this->browser->other('GET', LOGIN);

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect($response->headers->get('Allow'))->toContain('POST');
});

it('signs a platform admin in, with no institution [FR-INST-04] (scenario 1)', function () {
    $admin = Accounts::user(['role' => 'platform_admin']);

    $this->browser->login($admin['email'], $admin['password'])
        ->assertOk()
        ->assertJsonPath('data.role', 'platform_admin')
        ->assertJsonPath('data.institution', null);
});

it('rewrites a hash that is not Argon2id at sign-in [NFR-SEC-02] (scenario 1)', function () {
    $user = Accounts::user();
    $bcrypt = password_hash($user['password'], PASSWORD_BCRYPT);
    DB::connection(useMigratorConnection())->table('users')->where('uuid', $user['user'])->update(['password' => $bcrypt]);

    $this->browser->login($user['email'], $user['password'])->assertOk();

    expect(Accounts::userRow($user['email'])['password'])->toStartWith('$argon2id$');
});
