<?php

/*
 * DELETE /api/v1/invitations/{invitation} — docs/api/users/DELETE-invitations-{invitation}.md
 * Tenant suite, route api/v1/invitations/{invitation}: another institution's invitation
 * answers 404, the same as an unknown one (scenario 3), and a manager is refused (scenario 4).
 */

use Illuminate\Support\Carbon;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Team;

const UNKNOWN_INVITATION = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function cancelUrl(string $uuid): string
{
    return "/api/v1/invitations/{$uuid}";
}

function plantInvitationFor(array $account, string $email = 'guest@example.test', array $extra = []): array
{
    $token = Accounts::token();
    $uuid = Accounts::plantInvitation(array_merge(['institution' => $account['institution'], 'invited_by' => $account['user'], 'email' => $email, 'token' => $token], $extra));

    return ['uuid' => $uuid, 'token' => $token];
}

it('cancels a live invitation: the row is deleted and the link stops working [FR-INST-03] (scenario 1)', function () {
    $t = Team::two();
    $invitation = plantInvitationFor($t['a']['owner']);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->delete(cancelUrl($invitation['uuid']))->assertNoContent();

    expect(Accounts::invitationRows($t['a']['owner']['institution']))->toBe([]);
    (new AuthClient($this))->post('/api/v1/auth/accept-invitation', ['token' => $invitation['token'], 'name' => 'Guest', 'password' => 'un mot de passe long'])
        ->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

it('cancels an expired invitation [FR-INST-03] (scenario 2)', function () {
    $t = Team::two();
    $invitation = plantInvitationFor($t['a']['owner'], 'late@example.test', ['expires_at' => Carbon::now('UTC')->subDay()]);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->delete(cancelUrl($invitation['uuid']))->assertNoContent();

    expect(Accounts::invitationRows())->toBe([]);
});

it('answers 404, the same for every case, for an invitation that is unknown, accepted, cancelled, of another institution or not a UUID [FR-INST-05] (scenario 3)', function () {
    $t = Team::two();
    $accepted = plantInvitationFor($t['a']['owner'], 'done@example.test', ['accepted' => true]);
    $cancelled = plantInvitationFor($t['a']['owner'], 'gone@example.test');
    $foreign = plantInvitationFor($t['b']['owner'], 'foreign@example.test');
    Team::signIn($this, $t['a']['owner']);
    $this->browser->delete(cancelUrl($cancelled['uuid']))->assertNoContent();

    $unknown = Team::shape($this->browser->delete(cancelUrl(UNKNOWN_INVITATION)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$accepted['uuid'], $cancelled['uuid'], $foreign['uuid'], 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->delete(cancelUrl($target))))->toBe($unknown);
    }

    // Nothing of the other institution or of the accepted one changed.
    expect(Accounts::invitationRows($t['b']['owner']['institution']))->toHaveCount(1)
        ->and(Accounts::invitationRows($t['a']['owner']['institution']))->toHaveCount(1);
});

it('answers 403 to a manager and keeps the invitation [FR-INST-03] (scenario 4)', function () {
    $t = Team::two();
    $invitation = plantInvitationFor($t['a']['owner']);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->delete(cancelUrl($invitation['uuid']))->assertStatus(403)->assertJsonPath('error.code', 'forbidden');

    expect(Accounts::invitationRows())->toHaveCount(1);
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-03] (scenario 5)', function () {
    $t = Team::two();
    $invitation = plantInvitationFor($t['a']['owner']);

    $this->browser->delete(cancelUrl($invitation['uuid']))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::invitationRows())->toHaveCount(1);
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 6)', function () {
    $t = Team::two();
    $invitation = plantInvitationFor($t['a']['owner']);
    Team::signIn($this, $t['a']['owner']);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->delete(cancelUrl($invitation['uuid']))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::invitationRows())->toHaveCount(1);
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 7)', function () {
    $t = Team::two();
    $invitation = plantInvitationFor($t['a']['owner']);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->delete(cancelUrl($invitation['uuid']), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Accounts::invitationRows())->toHaveCount(1);
});

it('answers 405 to another method than DELETE [NFR-SEC-01] (scenario 8)', function (string $method) {
    $t = Team::two();
    $invitation = plantInvitationFor($t['a']['owner']);
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->other($method, cancelUrl($invitation['uuid']));

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect(array_map('trim', explode(',', (string) $response->headers->get('Allow'))))->toContain('DELETE');
})->with(['GET', 'POST', 'PUT', 'PATCH']);
