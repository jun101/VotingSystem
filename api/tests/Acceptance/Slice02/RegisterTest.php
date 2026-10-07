<?php

/*
 * POST /api/v1/auth/register — docs/api/auth/POST-auth-register.md
 * One test per scenario of that file.
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;

function validRegistration(array $override = []): array
{
    return array_merge([
        'institution_name' => 'Collège Les Flamboyants',
        'name' => 'Marie Joseph',
        'email' => 'marie@flamboyants.example',
        'password' => 'un mot de passe long',
        'language' => 'fr',
    ], $override);
}

function countOf(string $table): int
{
    return DB::connection(useMigratorConnection())->table($table)->count();
}

it('registers an institution and its owner, signs them in and sends the email [FR-INST-01] (scenario 1)', function () {
    $response = $this->browser->post('/api/v1/auth/register', validRegistration());

    $response->assertCreated();
    $data = $response->json('data');

    expect(array_keys($response->json()))->toBe(['data'])
        ->and(array_keys($data))->toEqualCanonicalizing(['id', 'name', 'email', 'role', 'email_verified', 'language', 'institution'])
        ->and($data['id'])->toMatch(UUID_V4)
        ->and($data['name'])->toBe('Marie Joseph')
        ->and($data['email'])->toBe('marie@flamboyants.example')
        ->and($data['role'])->toBe('owner')
        ->and($data['email_verified'])->toBeFalse()
        ->and($data['language'])->toBe('fr')
        ->and(array_keys($data['institution']))->toEqualCanonicalizing(['id', 'name', 'type'])
        ->and($data['institution']['id'])->toMatch(UUID_V4)
        ->and($data['institution']['name'])->toBe('Collège Les Flamboyants')
        ->and($data['institution']['type'])->toBe('other');

    // No password, hash or secret in the answer.
    expect($response->getContent())->not->toContain('argon')->not->toContain('un mot de passe long')->not->toContain('password');

    // The rows.
    $user = Accounts::userRow('marie@flamboyants.example');
    $institution = DB::connection(useMigratorConnection())->table('institutions')->where('uuid', $data['institution']['id'])->first();

    expect($user['uuid'])->toBe($data['id'])
        ->and($user['role'])->toBe('owner')
        ->and($user['email_verified_at'])->toBeNull()
        ->and($user['password'])->toStartWith('$argon2id$')
        ->and($institution)->not->toBeNull()
        ->and($institution->type)->toBe('other')
        ->and($institution->timezone)->toBe('America/Port-au-Prince')
        ->and($institution->language)->toBe('fr')
        ->and($institution->suspended_at)->toBeNull();

    // The user is signed in: the same browser is known.
    $me = $this->browser->get('/api/v1/auth/me');
    $me->assertOk()->assertJsonPath('data.id', $data['id']);
});

it('stores the email in lower case [FR-INST-01] (scenario 1)', function () {
    $response = $this->browser->post('/api/v1/auth/register', validRegistration(['email' => 'Marie.Joseph@Flamboyants.Example']));

    $response->assertCreated()->assertJsonPath('data.email', 'marie.joseph@flamboyants.example');
    expect(Accounts::userRow('marie.joseph@flamboyants.example'))->not->toBeNull();
});

it('trims the names [FR-INST-01] (scenario 1)', function () {
    $response = $this->browser->post('/api/v1/auth/register', validRegistration(['institution_name' => '  Collège Les Flamboyants  ', 'name' => ' Marie Joseph ']));

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Marie Joseph')
        ->assertJsonPath('data.institution.name', 'Collège Les Flamboyants');
});

it('sends one verification email with a token link and stores only its hash [FR-INST-01, NFR-SEC-08] (scenario 1)', function () {
    $this->browser->post('/api/v1/auth/register', validRegistration())->assertCreated();

    $mails = Accounts::mailTo('marie@flamboyants.example');
    expect($mails)->toHaveCount(1);

    $token = Accounts::tokenIn($mails[0], '/verify-email');
    expect($token)->not->toBeNull()->toMatch('/^[0-9a-f]{64}$/');

    // The link holds the token and nothing else: no id, no address.
    expect($mails[0]['text'].$mails[0]['html'])->not->toContain('marie%40')->not->toContain('?email=')->not->toContain('&email=');

    $rows = Accounts::tokenRows('email_verification_tokens', 'marie@flamboyants.example');
    expect($rows)->toHaveCount(1)
        ->and(strlen($rows[0]['token_hash']))->toBe(32)
        ->and($rows[0]['token_hash'])->toBe(hash('sha256', $token, true))
        ->and(json_encode(array_map('bin2hex', array_filter($rows[0], 'is_string'))))->not->toContain(bin2hex($token));

    $hours = Carbon::parse($rows[0]['expires_at'], 'UTC')->diffInHours(now('UTC'), true);
    expect($hours)->toBeBetween(23, 24.01);
});

it('writes the email in the language of the user [NFR-UX-01] (scenario 1)', function () {
    $this->browser->post('/api/v1/auth/register', validRegistration(['email' => 'a@example.test', 'language' => 'fr']))->assertCreated();
    $this->browser->forgetCookies();
    $this->browser->post('/api/v1/auth/register', validRegistration(['email' => 'b@example.test', 'language' => 'en']))->assertCreated();

    $fr = Accounts::mailTo('a@example.test')[0];
    $en = Accounts::mailTo('b@example.test')[0];

    expect($fr['subject'])->not->toBe($en['subject'])
        ->and(mb_strtolower($fr['subject']))->toContain('vérif')
        ->and(mb_strtolower($en['subject']))->toContain('verify')
        ->and($fr['text'])->not->toBe('')->and($fr['html'])->not->toBe('')
        ->and($en['text'])->not->toBe('')->and($en['html'])->not->toBe('');
});

it('takes the language from Accept-Language when none is sent [FR-INST-01] (scenario 1)', function () {
    $body = validRegistration();
    unset($body['language']);

    $this->browser->post('/api/v1/auth/register', $body, ['Accept-Language' => 'en'])
        ->assertCreated()->assertJsonPath('data.language', 'en');

    $this->browser->forgetCookies();
    $other = validRegistration(['email' => 'c@example.test']);
    unset($other['language']);

    $this->browser->post('/api/v1/auth/register', $other)
        ->assertCreated()->assertJsonPath('data.language', 'fr');
});

it('answers 422 when a required field is missing [FR-INST-01] (scenario 2)', function () {
    foreach (['institution_name', 'name', 'email', 'password'] as $field) {
        $body = validRegistration();
        unset($body[$field]);

        $response = $this->browser->post('/api/v1/auth/register', $body);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        expect($response->json("error.fields.{$field}"))->toContain('required');
    }

    expect(countOf('institutions'))->toBe(0)->and(countOf('users'))->toBe(0);
});

it('answers 422 for a field that is empty or too long [FR-INST-01] (scenario 2)', function () {
    foreach ([['institution_name', ''], ['institution_name', str_repeat('a', 151)], ['name', '   '], ['name', str_repeat('a', 151)]] as [$field, $value]) {
        $response = $this->browser->post('/api/v1/auth/register', validRegistration([$field => $value]));

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        expect($response->json('error.fields'))->toHaveKey($field);
    }
});

it('answers 422 when the email is not an address [FR-INST-01] (scenario 3)', function () {
    foreach (['marie', 'marie@', '@example.test', 'marie example@test.com', str_repeat('a', 250).'@example.test'] as $email) {
        $response = $this->browser->post('/api/v1/auth/register', validRegistration(['email' => $email]));

        $response->assertStatus(422);
        expect($response->json('error.fields'))->toHaveKey('email');
    }

    $response = $this->browser->post('/api/v1/auth/register', validRegistration(['email' => 'marie']));
    expect($response->json('error.fields.email'))->toContain('invalid');
});

it('answers 422 when the email already has an account, in any letter case [FR-INST-01] (scenario 4)', function () {
    Accounts::user(['email' => 'marie@flamboyants.example']);

    foreach (['marie@flamboyants.example', 'MARIE@Flamboyants.Example'] as $email) {
        $response = $this->browser->post('/api/v1/auth/register', validRegistration(['email' => $email]));

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        expect($response->json('error.fields.email'))->toContain('taken');
    }

    expect(countOf('institutions'))->toBe(1)->and(countOf('users'))->toBe(1)
        ->and(Accounts::mail())->toBe([]);
});

it('answers 422 when the password is shorter than 12 characters [NFR-SEC-02] (scenario 5)', function () {
    $response = $this->browser->post('/api/v1/auth/register', validRegistration(['password' => 'elevenchars']));
    expect(strlen('elevenchars'))->toBe(11);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.password'))->toContain('min');

    // 12 characters is enough, and the password is counted in characters, not bytes.
    $this->browser->post('/api/v1/auth/register', validRegistration(['password' => 'douzecaractè']))->assertCreated();
});

it('answers 422 when the password is longer than 128 characters [NFR-SEC-02] (scenario 5)', function () {
    $response = $this->browser->post('/api/v1/auth/register', validRegistration(['password' => str_repeat('a', 129)]));

    $response->assertStatus(422);
    expect($response->json('error.fields'))->toHaveKey('password');

    $this->browser->post('/api/v1/auth/register', validRegistration(['password' => str_repeat('a', 128)]))->assertCreated();
});

it('answers 422 when the password equals the email [NFR-SEC-02] (scenario 6)', function () {
    $response = $this->browser->post('/api/v1/auth/register', validRegistration(['email' => 'marie.joseph@example.test', 'password' => 'marie.joseph@example.test']));

    $response->assertStatus(422);
    expect($response->json('error.fields.password'))->toContain('same_as_email');
});

it('answers 422 when the language is neither fr nor en [FR-INST-01] (scenario 7)', function () {
    $response = $this->browser->post('/api/v1/auth/register', validRegistration(['language' => 'es']));

    $response->assertStatus(422);
    expect($response->json('error.fields.language'))->toContain('invalid');
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 8)', function () {
    $response = $this->browser->postRaw('/api/v1/auth/register', '{"email": ');

    $response->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
    expect(countOf('users'))->toBe(0);
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 9)', function () {
    $response = $this->browser->post('/api/v1/auth/register', validRegistration(), [], csrf: false);
    $response->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');

    // A browser that has the cookies but sends a wrong header.
    $this->browser->csrf();
    $response = $this->browser->post('/api/v1/auth/register', validRegistration(), ['X-XSRF-TOKEN' => 'not-the-token'], csrf: false);
    $response->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');

    expect(countOf('users'))->toBe(0)->and(Accounts::mail())->toBe([]);
});

it('answers 429 above 10 requests an hour from one address [NFR-SEC-05] (scenario 10)', function () {
    foreach (range(1, 10) as $i) {
        $this->browser->post('/api/v1/auth/register', [])->assertStatus(422);
    }

    $response = $this->browser->post('/api/v1/auth/register', validRegistration());

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(countOf('users'))->toBe(0);
});

it('answers 405 to another method than POST [NFR-SEC-01] (scenario 11)', function () {
    $response = $this->browser->other('GET', '/api/v1/auth/register');

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect($response->headers->get('Allow'))->toContain('POST');
});
