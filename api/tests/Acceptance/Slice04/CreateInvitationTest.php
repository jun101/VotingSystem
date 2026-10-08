<?php

/*
 * POST /api/v1/invitations — docs/api/users/POST-invitations.md
 * Tenant suite, route api/v1/invitations: the invitation is written in the caller's institution
 * whatever the body says, and a manager is refused (scenario 8).
 */

use Illuminate\Support\Carbon;
use Tests\Support\Accounts;
use Tests\Support\Team;

const INVITE = '/api/v1/invitations';

function inviteAsOwner($test, ?string $language = null): array
{
    $t = Team::two();
    if ($language !== null) {
        Accounts::updateInstitution($t['a']['owner']['institution'], ['language' => $language]);
    }
    Team::signIn($test, $t['a']['owner']);

    return $t;
}

it('creates an invitation, stores only the hash of the token and sends one email [FR-INST-03] (scenario 1)', function () {
    $t = inviteAsOwner($this);

    $response = $this->browser->post(INVITE, ['email' => 'Jean.Pierre@Example.test', 'role' => 'manager'])->assertCreated();

    expect(array_keys($response->json()))->toBe(['data'])
        ->and(array_keys($response->json('data')))->toEqualCanonicalizing(['id', 'email', 'role', 'invited_by', 'created_at', 'expires_at', 'expired'])
        ->and($response->json('data'))->toMatchArray(['email' => 'jean.pierre@example.test', 'role' => 'manager', 'invited_by' => 'Alice Owner A', 'expired' => false])
        ->and($response->json('data.id'))->toMatch(UUID_V4);

    $created = Carbon::parse($response->json('data.created_at'));
    expect($created->diffInDays(Carbon::parse($response->json('data.expires_at'))))->toBe(7.0);

    $rows = Accounts::invitationRows($t['a']['owner']['institution']);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['uuid'])->toBe($response->json('data.id'))
        ->and($rows[0]['email'])->toBe('jean.pierre@example.test')
        ->and($rows[0]['accepted_at'])->toBeNull()
        ->and(strlen($rows[0]['token_hash']))->toBe(32);

    $mails = Accounts::mailTo('jean.pierre@example.test');
    expect($mails)->toHaveCount(1);
    $token = Accounts::tokenIn($mails[0], '/accept-invitation');
    expect($token)->not->toBeNull()
        ->and(hash('sha256', $token, true))->toBe($rows[0]['token_hash']);

    // The token is nowhere in the answer.
    expect($response->getContent())->not->toContain($token);
});

it('writes the email in the language of the inviting institution, with a link holding a token only [FR-INST-03, NFR-UX-01] (scenario 1)', function (string $language, string $needle) {
    inviteAsOwner($this, $language);

    $this->browser->post(INVITE, ['email' => 'guest@example.test', 'role' => 'owner'])->assertCreated();

    $mail = Accounts::mailTo('guest@example.test')[0];
    expect($mail['html'])->toContain("lang=\"{$language}\"")
        ->and($mail['text'].$mail['html'])->toContain($needle)
        ->and($mail['text'])->toMatch('#'.preg_quote(rtrim((string) config('app.url'), '/'), '#').'/accept-invitation\?token=[0-9a-f]{64}\s#');

    // Nothing else in the link: no address, no id, no name.
    preg_match_all('#/accept-invitation\?[^\s"<]*#', $mail['text'], $links);
    foreach ($links[0] as $link) {
        expect($link)->toMatch('#^/accept-invitation\?token=[0-9a-f]{64}$#');
    }
})->with([['fr', 'rejoindre'], ['en', 'join']]);

it('replaces a live invitation for the same address: the old link stops working [FR-INST-03] (scenario 2)', function () {
    $t = inviteAsOwner($this);

    $this->browser->post(INVITE, ['email' => 'guest@example.test', 'role' => 'manager'])->assertCreated();
    $first = Accounts::tokenIn(Accounts::mailTo('guest@example.test')[0], '/accept-invitation');

    $this->browser->post(INVITE, ['email' => 'GUEST@example.test', 'role' => 'owner'])->assertCreated();

    $rows = Accounts::invitationRows($t['a']['owner']['institution']);
    $mails = Accounts::mailTo('guest@example.test');
    $second = Accounts::tokenIn($mails[1], '/accept-invitation');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['role'])->toBe('owner')
        ->and($mails)->toHaveCount(2)
        ->and($second)->not->toBe($first)
        ->and($rows[0]['token_hash'])->toBe(hash('sha256', $second, true));

    $fresh = new Tests\Support\AuthClient($this);
    $fresh->post('/api/v1/auth/accept-invitation', ['token' => $first, 'name' => 'Guest', 'password' => 'un mot de passe long'])
        ->assertStatus(404);
});

it('replaces an expired invitation for the same address [FR-INST-03] (scenario 3)', function () {
    $t = inviteAsOwner($this);
    Accounts::plantInvitation(['institution' => $t['a']['owner']['institution'], 'invited_by' => $t['a']['owner']['user'], 'email' => 'late@example.test', 'token' => Accounts::token(), 'expires_at' => Carbon::now('UTC')->subDay()]);

    $response = $this->browser->post(INVITE, ['email' => 'late@example.test', 'role' => 'manager'])->assertCreated();

    expect($response->json('data.expired'))->toBeFalse()
        ->and(Accounts::invitationRows($t['a']['owner']['institution']))->toHaveCount(1);
});

it('answers 422 when email or role is missing [FR-INST-03] (scenario 4)', function (array $body, string $field) {
    inviteAsOwner($this);

    $response = $this->browser->post(INVITE, $body);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json("error.fields.{$field}"))->toContain('required')
        ->and(Accounts::mail())->toBe([]);
})->with([[['role' => 'manager'], 'email'], [['email' => 'a@example.test'], 'role'], [['email' => '', 'role' => 'manager'], 'email']]);

it('answers 422 when the email is not an address or is too long [FR-INST-03] (scenario 5)', function (string $email, string $rule) {
    inviteAsOwner($this);

    $response = $this->browser->post(INVITE, ['email' => $email, 'role' => 'manager']);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.email'))->toContain($rule)
        ->and(Accounts::mail())->toBe([]);
})->with([['not an address', 'email'], ['a@', 'email'], ['x@'.str_repeat(str_repeat('d', 60).'.', 5).'test', 'max']]);

it('answers 422 when the role is not owner or manager [FR-INST-03] (scenario 6)', function (mixed $role) {
    inviteAsOwner($this);

    $response = $this->browser->post(INVITE, ['email' => 'guest@example.test', 'role' => $role]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.role'))->toContain('in')
        ->and(Accounts::mail())->toBe([]);
})->with([['platform_admin'], ['Owner'], ['admin'], [1]]);

it('answers 422 when the address already belongs to a user, in any letter case and any institution [FR-INST-03] (scenario 7)', function (string $email) {
    $t = inviteAsOwner($this);

    $response = $this->browser->post(INVITE, ['email' => $email, 'role' => 'manager']);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.email'))->toContain('taken')
        ->and(Accounts::invitationRows($t['a']['owner']['institution']))->toBe([])
        ->and(Accounts::mail())->toBe([]);
})->with(['armand.a@example.test', 'ARMAND.A@Example.TEST', 'bruno.b@example.test']);

it('answers 403 to a manager and sends nothing [FR-INST-03] (scenario 8)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['manager']);

    $this->browser->post(INVITE, ['email' => 'guest@example.test', 'role' => 'manager'])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');

    expect(Accounts::invitationRows())->toBe([])
        ->and(Accounts::mail())->toBe([]);
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-03] (scenario 9)', function () {
    $this->browser->post(INVITE, ['email' => 'guest@example.test', 'role' => 'manager'])
        ->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::invitationRows())->toBe([]);
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 10)', function () {
    $t = inviteAsOwner($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->post(INVITE, ['email' => 'guest@example.test', 'role' => 'manager'])
        ->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::invitationRows())->toBe([]);
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 11)', function () {
    inviteAsOwner($this);

    $this->browser->post(INVITE, ['email' => 'guest@example.test', 'role' => 'manager'], [], false)
        ->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Accounts::invitationRows())->toBe([]);
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 12)', function () {
    inviteAsOwner($this);

    $this->browser->postRaw(INVITE, '{"email": "guest@example.test"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 20 invitations an hour from one user [NFR-SEC-05] (scenario 13)', function () {
    inviteAsOwner($this);

    foreach (range(1, 20) as $i) {
        $this->browser->post(INVITE, ['email' => "guest{$i}@example.test", 'role' => 'manager'])->assertCreated();
    }

    $response = $this->browser->post(INVITE, ['email' => 'one-more@example.test', 'role' => 'manager']);

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(Accounts::mailTo('one-more@example.test'))->toBe([]);
});

it('answers 405 to another method than GET, HEAD or POST [NFR-SEC-01] (scenario 14)', function (string $method) {
    inviteAsOwner($this);

    $this->browser->other($method, INVITE)->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['PUT', 'PATCH', 'DELETE']);

it('writes the invitation in the caller\'s institution whatever the body says [FR-INST-05]', function () {
    $t = inviteAsOwner($this);

    $this->browser->post(INVITE, [
        'email' => 'guest@example.test',
        'role' => 'manager',
        'institution' => $t['b']['owner']['institution'],
        'institution_id' => 2,
        'invited_by' => $t['b']['owner']['user'],
    ])->assertCreated();

    expect(Accounts::invitationRows($t['b']['owner']['institution']))->toBe([])
        ->and(Accounts::invitationRows($t['a']['owner']['institution']))->toHaveCount(1);
});

it('does not log the address or the token [FR-INST-03, NFR-SEC-05]', function () {
    inviteAsOwner($this);

    $this->browser->post(INVITE, ['email' => 'quiet.guest@example.test', 'role' => 'manager'])->assertCreated();
    $token = Accounts::tokenIn(Accounts::mailTo('quiet.guest@example.test')[0], '/accept-invitation');

    foreach (glob(storage_path('logs/*.log')) ?: [] as $log) {
        $content = file_get_contents($log);
        expect($content)->not->toContain('quiet.guest@example.test')->not->toContain((string) $token);
    }
});
