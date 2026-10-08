<?php

/*
 * POST /api/v1/auth/accept-invitation — docs/api/auth/POST-auth-accept-invitation.md
 * Public route: it removes the tenant scope by name to find the token before anyone is signed in.
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Team;

const ACCEPT = '/api/v1/auth/accept-invitation';
const NEW_PASSWORD = 'un mot de passe long et sûr';

/** An owner of an institution, an invitation they sent, and its token. */
function invitedBy(array $options = []): array
{
    $owner = Accounts::user(['language' => $options['language'] ?? 'fr'] + ($options['owner'] ?? []));
    $token = Accounts::token();
    $email = $options['email'] ?? 'jean.pierre@example.test';
    $uuid = Accounts::plantInvitation([
        'institution' => $owner['institution'],
        'invited_by' => $owner['user'],
        'email' => $email,
        'role' => $options['role'] ?? 'manager',
        'token' => $token,
        'expires_at' => $options['expires_at'] ?? null,
        'accepted' => $options['accepted'] ?? false,
    ] + []);

    return ['owner' => $owner, 'token' => $token, 'email' => $email, 'invitation' => $uuid];
}

function acceptBody(array $invited, array $override = []): array
{
    return array_merge(['token' => $invited['token'], 'name' => 'Jean Pierre', 'password' => NEW_PASSWORD], $override);
}

it('creates the user, signs them in and answers the current user [FR-INST-03] (scenario 1)', function () {
    $invited = invitedBy(['role' => 'manager']);

    $response = $this->browser->post(ACCEPT, acceptBody($invited))->assertOk();

    expect(array_keys($response->json()))->toBe(['data'])
        ->and(array_keys($response->json('data')))->toEqualCanonicalizing(['id', 'name', 'email', 'role', 'email_verified', 'language', 'institution'])
        ->and($response->json('data'))->toMatchArray(['name' => 'Jean Pierre', 'email' => 'jean.pierre@example.test', 'role' => 'manager', 'email_verified' => true, 'language' => 'fr'])
        ->and($response->json('data.institution.id'))->toBe($invited['owner']['institution'])
        ->and($response->json('data.id'))->toMatch(UUID_V4);

    $row = Accounts::userRow('jean.pierre@example.test');
    expect($row['uuid'])->toBe($response->json('data.id'))
        ->and($row['email_verified_at'])->not->toBeNull()
        ->and($row['last_login_at'])->not->toBeNull()
        ->and($row['password'])->toStartWith('$argon2id$')
        ->and($row['deleted_at'])->toBeNull();

    // Signed in: the session works, and the invitation is used up.
    $this->browser->get('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'jean.pierre@example.test');
    expect(Accounts::invitationRows($invited['owner']['institution'])[0]['accepted_at'])->not->toBeNull();

    // No password, hash or token in the answer.
    expect($response->getContent())->not->toContain(NEW_PASSWORD)->not->toContain('argon')->not->toContain($invited['token']);
});

it('gives the invited role and the institution\'s default language [FR-INST-03] (scenario 1)', function () {
    $invited = invitedBy(['role' => 'owner', 'language' => 'en']);

    $response = $this->browser->post(ACCEPT, acceptBody($invited))->assertOk();

    expect($response->json('data.role'))->toBe('owner')
        ->and($response->json('data.language'))->toBe('en');
    // An owner by invitation can use the owner endpoints.
    $this->browser->get('/api/v1/users')->assertOk();
});

it('regenerates the session id at sign-in [NFR-SEC-04] (scenario 1)', function () {
    $invited = invitedBy();
    $this->browser->csrf();
    $before = $this->browser->cookie(config('session.cookie'));

    $this->browser->post(ACCEPT, acceptBody($invited))->assertOk();

    expect($this->browser->cookie(config('session.cookie')))->not->toBe($before);
});

it('uses the token once [FR-INST-03] (scenario 1)', function () {
    $invited = invitedBy();
    $this->browser->post(ACCEPT, acceptBody($invited))->assertOk();

    $other = new AuthClient($this);
    $other->post(ACCEPT, acceptBody($invited, ['name' => 'Someone Else']))->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

it('answers 422 when token, name or password is missing [FR-INST-03] (scenario 2)', function (string $field) {
    $invited = invitedBy();
    $body = acceptBody($invited);
    unset($body[$field]);

    $response = $this->browser->post(ACCEPT, $body);

    // A missing token cannot be a valid token: it is a validation error, never a 404.
    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json("error.fields.{$field}"))->toContain('required')
        ->and(Accounts::userRow($invited['email']))->toBeNull();
})->with(['token', 'name', 'password']);

it('answers 422 when the name is empty or longer than 150 characters [FR-INST-03] (scenario 3)', function (string $name, string $rule) {
    $invited = invitedBy();

    $response = $this->browser->post(ACCEPT, acceptBody($invited, ['name' => $name]));

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.name'))->toContain($rule)
        ->and(Accounts::userRow($invited['email']))->toBeNull();
})->with([['', 'required'], ['   ', 'required'], [str_repeat('é', 151), 'max']]);

it('answers 422 when the password is shorter than 12 or longer than 128 characters [NFR-SEC-02] (scenario 4)', function () {
    $invited = invitedBy();

    $short = $this->browser->post(ACCEPT, acceptBody($invited, ['password' => 'elevenchars']));
    $short->assertStatus(422);
    expect($short->json('error.fields.password'))->toContain('min');

    $long = $this->browser->post(ACCEPT, acceptBody($invited, ['password' => str_repeat('a', 129)]));
    $long->assertStatus(422);
    expect($long->json('error.fields.password'))->toContain('max')
        ->and(Accounts::userRow($invited['email']))->toBeNull();

    $this->browser->post(ACCEPT, acceptBody($invited, ['password' => 'douzecaractè']))->assertOk();
});

it('answers 422 when the password equals the invited address [NFR-SEC-02] (scenario 5)', function () {
    $invited = invitedBy(['email' => 'marie.joseph@example.test']);

    $response = $this->browser->post(ACCEPT, acceptBody($invited, ['password' => 'marie.joseph@example.test']));

    $response->assertStatus(422);
    expect($response->json('error.fields.password'))->toContain('same_as_email')
        ->and(Accounts::userRow('marie.joseph@example.test'))->toBeNull();
});

it('answers 404, the same for every cause, for an unknown, cancelled or already accepted token [FR-INST-03] (scenario 6)', function () {
    $accepted = invitedBy(['accepted' => true, 'email' => 'done@example.test']);
    $cancelled = invitedBy(['email' => 'gone@example.test']);
    DB::connection(useMigratorConnection())->table('invitations')->where('uuid', $cancelled['invitation'])->delete();
    $live = invitedBy(['email' => 'live@example.test']);

    $unknown = Team::shape($this->browser->post(ACCEPT, acceptBody($live, ['token' => Accounts::token()])));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$accepted['token'], $cancelled['token'], 'short', str_repeat('z', 64)] as $token) {
        $browser = new AuthClient($this);
        expect(Team::shape($browser->post(ACCEPT, acceptBody($live, ['token' => $token]))))->toBe($unknown);
    }

    expect(Accounts::userRow('done@example.test'))->toBeNull()
        ->and(Accounts::userRow('gone@example.test'))->toBeNull();
});

it('answers 404 before any 422 about the other fields, so a bad body never reveals a valid token [FR-INST-03] (scenario 6)', function () {
    $this->browser->post(ACCEPT, ['token' => Accounts::token(), 'name' => '', 'password' => 'x'])
        ->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

it('answers 410 when the invitation has expired, and creates nothing [FR-INST-03] (scenario 7)', function () {
    $invited = invitedBy(['expires_at' => Carbon::now('UTC')->subMinute()]);

    $response = $this->browser->post(ACCEPT, acceptBody($invited));

    $response->assertStatus(410)->assertJsonPath('error.code', 'expired');
    expect(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message'])
        ->and(Accounts::userRow($invited['email']))->toBeNull();
});

it('answers 409 when the address has become a user since [FR-INST-03] (scenario 8)', function () {
    $invited = invitedBy(['email' => 'busy@example.test']);
    Accounts::user(['email' => 'busy@example.test']);

    $response = $this->browser->post(ACCEPT, acceptBody($invited));

    $response->assertStatus(409)->assertJsonPath('error.code', 'email_taken');
    expect(Accounts::invitationRows($invited['owner']['institution'])[0]['accepted_at'])->toBeNull();
});

it('lets the second of two institutions that invited one address fail with 409 once the first accepted [FR-INST-03] (scenario 8)', function () {
    $first = invitedBy(['email' => 'shared@example.test']);
    $second = invitedBy(['email' => 'shared@example.test']);

    $this->browser->post(ACCEPT, acceptBody($first))->assertOk();
    (new AuthClient($this))->post(ACCEPT, acceptBody($second))->assertStatus(409)->assertJsonPath('error.code', 'email_taken');
});

it('answers 403 when the inviting institution is suspended, and creates nothing [FR-INST-06] (scenario 9)', function () {
    $invited = invitedBy();
    Accounts::suspend($invited['owner']['institution']);

    $this->browser->post(ACCEPT, acceptBody($invited))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::userRow($invited['email']))->toBeNull();
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 10)', function () {
    $invited = invitedBy();

    $this->browser->post(ACCEPT, acceptBody($invited), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Accounts::userRow($invited['email']))->toBeNull();
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 11)', function () {
    $this->browser->postRaw(ACCEPT, '{"token": "x"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 10 requests an hour from one address [NFR-SEC-05] (scenario 12)', function () {
    foreach (range(1, 10) as $i) {
        $this->browser->post(ACCEPT, ['token' => Accounts::token(), 'name' => 'X', 'password' => NEW_PASSWORD])->assertStatus(404);
    }

    $response = $this->browser->post(ACCEPT, ['token' => Accounts::token(), 'name' => 'X', 'password' => NEW_PASSWORD]);

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('answers 405 to another method than POST [NFR-SEC-01] (scenario 13)', function (string $method) {
    $response = $this->browser->other($method, ACCEPT);

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect($response->headers->get('Allow'))->toContain('POST');
})->with(['GET', 'PUT', 'PATCH', 'DELETE']);

it('lets the new user sign in again with the password they chose [FR-INST-03]', function () {
    $invited = invitedBy();
    $this->browser->post(ACCEPT, acceptBody($invited))->assertOk();

    (new AuthClient($this))->login($invited['email'], NEW_PASSWORD)->assertOk()->assertJsonPath('data.role', 'manager');
});

it('does not log the token, the address or the password [NFR-SEC-05]', function () {
    $invited = invitedBy(['email' => 'quiet.person@example.test']);

    $this->browser->post(ACCEPT, acceptBody($invited))->assertOk();

    foreach (glob(storage_path('logs/*.log')) ?: [] as $log) {
        $content = file_get_contents($log);
        expect($content)->not->toContain($invited['token'])->not->toContain('quiet.person@example.test')->not->toContain(NEW_PASSWORD);
    }
});
