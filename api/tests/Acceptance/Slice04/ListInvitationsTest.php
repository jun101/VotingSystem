<?php

/*
 * GET /api/v1/invitations — docs/api/users/GET-invitations.md
 * Tenant suite, route api/v1/invitations: another institution's invitations are never listed
 * (scenario 3), and a manager of this institution is refused (scenario 4).
 */

use Illuminate\Support\Carbon;
use Tests\Support\Accounts;
use Tests\Support\Team;

const INVITATIONS = '/api/v1/invitations';

it('lists live and expired invitations, newest first, with the shape of the file [FR-INST-03] (scenario 1)', function () {
    $t = Team::two();
    $a = $t['a']['owner'];
    Accounts::plantInvitation(['institution' => $a['institution'], 'invited_by' => $a['user'], 'email' => 'old@example.test', 'role' => 'manager', 'token' => Accounts::token(), 'expires_at' => Carbon::now('UTC')->subDay()]);
    $live = Accounts::plantInvitation(['institution' => $a['institution'], 'invited_by' => $a['user'], 'email' => 'new@example.test', 'role' => 'owner', 'token' => Accounts::token()]);
    Team::signIn($this, $a);

    $response = $this->browser->get(INVITATIONS)->assertOk();

    expect(array_keys($response->json()))->toBe(['data', 'meta'])
        ->and($response->json('meta'))->toBe(['page' => 1, 'per_page' => 25, 'total' => 2])
        ->and(array_column($response->json('data'), 'email'))->toBe(['new@example.test', 'old@example.test'])
        ->and(array_keys($response->json('data.0')))->toEqualCanonicalizing(['id', 'email', 'role', 'invited_by', 'created_at', 'expires_at', 'expired'])
        ->and($response->json('data.0'))->toMatchArray(['id' => $live, 'role' => 'owner', 'invited_by' => 'Alice Owner A', 'expired' => false])
        ->and($response->json('data.1.expired'))->toBeTrue()
        ->and($response->json('data.0.expires_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    // The token is never returned.
    expect($response->getContent())->not->toContain('token')->not->toContain('hash');
});

it('does not list an accepted invitation [FR-INST-03] (scenario 2)', function () {
    $t = Team::two();
    $a = $t['a']['owner'];
    Accounts::plantInvitation(['institution' => $a['institution'], 'invited_by' => $a['user'], 'email' => 'done@example.test', 'token' => Accounts::token(), 'accepted' => true]);
    Team::signIn($this, $a);

    $this->browser->get(INVITATIONS)->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
});

it('lists only the invitations of this institution [FR-INST-05] (scenario 3)', function () {
    $t = Team::two();
    Accounts::plantInvitation(['institution' => $t['a']['owner']['institution'], 'invited_by' => $t['a']['owner']['user'], 'email' => 'for-a@example.test', 'token' => Accounts::token()]);
    Accounts::plantInvitation(['institution' => $t['b']['owner']['institution'], 'invited_by' => $t['b']['owner']['user'], 'email' => 'for-b@example.test', 'token' => Accounts::token()]);
    Team::signIn($this, $t['b']['owner']);

    $response = $this->browser->get(INVITATIONS)->assertOk();

    expect(array_column($response->json('data'), 'email'))->toBe(['for-b@example.test'])
        ->and($response->getContent())->not->toContain('for-a@example.test');
});

it('answers 403 to a manager [FR-INST-03] (scenario 4)', function () {
    $t = Team::two();
    Accounts::plantInvitation(['institution' => $t['a']['owner']['institution'], 'invited_by' => $t['a']['owner']['user'], 'email' => 'secret@example.test', 'token' => Accounts::token()]);
    Team::signIn($this, $t['a']['manager']);

    $response = $this->browser->get(INVITATIONS);

    $response->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    expect($response->getContent())->not->toContain('secret@example.test');
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-03] (scenario 5)', function () {
    $this->browser->get(INVITATIONS)->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 6)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->get(INVITATIONS)->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 422 for a page size or a page out of range [FR-INST-03] (scenario 7)', function (string $query, string $field) {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->get(INVITATIONS.$query);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields'))->toHaveKey($field);
})->with([['?per_page=101', 'per_page'], ['?per_page=0', 'per_page'], ['?page=0', 'page']]);

it('answers 405 to another method than GET, HEAD or POST [NFR-SEC-01] (scenario 8)', function (string $method) {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->other($method, INVITATIONS);

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $allow = array_map('trim', explode(',', (string) $response->headers->get('Allow')));
    expect($allow)->toContain('GET')->toContain('POST')->not->toContain($method);
})->with(['PUT', 'PATCH', 'DELETE']);
