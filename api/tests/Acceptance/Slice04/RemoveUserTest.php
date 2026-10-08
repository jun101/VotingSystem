<?php

/*
 * DELETE /api/v1/users/{user} — docs/api/users/DELETE-users-{user}.md
 * Tenant suite, route api/v1/users/{user}: another institution's user answers 404, the same
 * as an unknown one (scenario 5), and a manager of this institution is refused (scenario 7).
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Team;

const UNKNOWN_USER = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function removeUrl(string $uuid): string
{
    return "/api/v1/users/{$uuid}";
}

it('removes a manager: soft delete, address freed, name kept [FR-INST-03] (scenario 1)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);
    $manager = $t['a']['manager'];

    $this->browser->delete(removeUrl($manager['user']))->assertNoContent();

    $row = Accounts::userRowByUuid($manager['user']);
    expect($row['deleted_at'])->not->toBeNull()
        ->and($row['email'])->toBe("removed-{$manager['user']}@removed.invalid")
        ->and($row['name'])->toBe('Armand Manager A');

    // Not listed any more; the real address is free for an invitation.
    $list = $this->browser->get('/api/v1/users')->assertOk();
    expect(array_column($list->json('data'), 'id'))->not->toContain($manager['user']);
    $this->browser->post('/api/v1/invitations', ['email' => $manager['email'], 'role' => 'manager'])->assertCreated();
});

it('ends the removed user\'s access: the next request is 401 and signing in fails like an unknown email [FR-INST-03] (scenario 1)', function () {
    $t = Team::two();
    $manager = new AuthClient($this);
    $manager->login($t['a']['manager']['email'], $t['a']['manager']['password'])->assertOk();
    $manager->get('/api/v1/auth/me')->assertOk();

    Team::signIn($this, $t['a']['owner']);
    $this->browser->delete(removeUrl($t['a']['manager']['user']))->assertNoContent();

    $manager->get('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    $again = (new AuthClient($this))->login($t['a']['manager']['email'], $t['a']['manager']['password']);
    $unknown = (new AuthClient($this))->login('nobody@example.test', 'whatever whatever');
    $again->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
    expect($again->json())->toBe($unknown->json());
});

it('deletes the pending verification and reset tokens of the removed user [FR-INST-03] (scenario 1)', function () {
    $t = Team::two();
    Accounts::plantToken('email_verification_tokens', $t['a']['manager']['email'], Accounts::token());
    Accounts::plantToken('password_reset_tokens', $t['a']['manager']['email'], Accounts::token());
    Team::signIn($this, $t['a']['owner']);

    $this->browser->delete(removeUrl($t['a']['manager']['user']))->assertNoContent();

    $userId = DB::connection(useMigratorConnection())->table('users')->where('uuid', $t['a']['manager']['user'])->value('id');
    foreach (['email_verification_tokens', 'password_reset_tokens'] as $table) {
        expect(DB::connection(useMigratorConnection())->table($table)->where('user_id', $userId)->count())->toBe(0);
    }
});

it('keeps the invitations the removed user sent [FR-INST-03] (scenario 1)', function () {
    $t = Team::two();
    Accounts::plantInvitation(['institution' => $t['a']['owner']['institution'], 'invited_by' => $t['a']['owner2']['user'], 'email' => 'guest@example.test', 'token' => Accounts::token()]);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->delete(removeUrl($t['a']['owner2']['user']))->assertNoContent();

    expect(Accounts::invitationRows($t['a']['owner']['institution']))->toHaveCount(1);
});

it('removes another owner while an owner remains [FR-INST-03] (scenario 2)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $this->browser->delete(removeUrl($t['a']['owner2']['user']))->assertNoContent();

    expect(Accounts::userRowByUuid($t['a']['owner2']['user'])['deleted_at'])->not->toBeNull();
});

it('lets an owner remove themself when another owner exists, and ends their session [FR-INST-03] (scenario 3)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $this->browser->delete(removeUrl($t['a']['owner']['user']))->assertNoContent();

    expect(Accounts::userRowByUuid($t['a']['owner']['user'])['deleted_at'])->not->toBeNull();
    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
});

it('refuses to remove the only owner, oneself or the last one left [FR-INST-03] (scenario 4)', function () {
    $t = Team::two();
    Team::signIn($this, $t['b']['owner']);

    $response = $this->browser->delete(removeUrl($t['b']['owner']['user']));

    $response->assertStatus(409)->assertJsonPath('error.code', 'last_owner');
    expect(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message'])
        ->and(Accounts::userRowByUuid($t['b']['owner']['user'])['deleted_at'])->toBeNull();

    // Two owners: one is removed, then the one left cannot be.
    Accounts::user(['role' => 'owner', 'institution' => $t['b']['owner']['institution'], 'email' => 'b.second@example.test']);
    $second = Accounts::userRow('b.second@example.test');
    $this->browser->delete(removeUrl($second['uuid']))->assertNoContent();
    $this->browser->delete(removeUrl($t['b']['owner']['user']))->assertStatus(409)->assertJsonPath('error.code', 'last_owner');
});

it('leaves exactly one owner when two owners remove each other [FR-INST-03] (scenario 4)', function () {
    $t = Team::two();
    // The institution has owner and owner2 (A); the manager is not an owner.
    $second = new AuthClient($this);
    $second->login($t['a']['owner2']['email'], $t['a']['owner2']['password'])->assertOk();
    Team::signIn($this, $t['a']['owner']);

    $first = $this->browser->delete(removeUrl($t['a']['owner2']['user']));
    $other = $second->delete(removeUrl($t['a']['owner']['user']));

    $statuses = [$first->getStatusCode(), $other->getStatusCode()];
    sort($statuses);
    // The second request finds its own session gone (401) or the last-owner rule (409): never two 204.
    expect(in_array($statuses, [[204, 401], [204, 409]], true))->toBeTrue('statuses: '.implode(',', $statuses));

    $owners = DB::connection(useMigratorConnection())->table('users')
        ->whereIn('uuid', [$t['a']['owner']['user'], $t['a']['owner2']['user']])->whereNull('deleted_at')->count();
    expect($owners)->toBe(1);
});

it('answers 404, the same for every case, for a user that is unknown, removed, of another institution or a platform admin [FR-INST-05] (scenario 5 and 6)', function () {
    $t = Team::two();
    $removed = Accounts::user(['role' => 'manager', 'institution' => $t['a']['owner']['institution'], 'removed' => true]);
    $admin = Accounts::user(['role' => 'platform_admin']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->delete(removeUrl(UNKNOWN_USER)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$t['b']['manager']['user'], $t['b']['owner']['user'], $removed['user'], $admin['user'], 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->delete(removeUrl($target))))->toBe($unknown);
    }

    // Nothing of the other institution changed.
    expect(Accounts::userRowByUuid($t['b']['manager']['user'])['deleted_at'])->toBeNull()
        ->and(Accounts::userRowByUuid($admin['user'])['deleted_at'])->toBeNull();
});

it('answers 403 to a manager, and 404 first for another institution\'s user [FR-INST-03] (scenario 7)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['manager']);

    $this->browser->delete(removeUrl($t['a']['owner']['user']))->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    expect(Accounts::userRowByUuid($t['a']['owner']['user'])['deleted_at'])->toBeNull();

    // The role is checked on a record of this institution only; another institution's is a 404.
    $this->browser->delete(removeUrl($t['b']['owner']['user']))->assertStatus(404);
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-03] (scenario 8)', function () {
    $t = Team::two();

    $this->browser->delete(removeUrl($t['a']['manager']['user']))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::userRowByUuid($t['a']['manager']['user'])['deleted_at'])->toBeNull();
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 9)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->delete(removeUrl($t['a']['manager']['user']))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::userRowByUuid($t['a']['manager']['user'])['deleted_at'])->toBeNull();
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 10)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $this->browser->delete(removeUrl($t['a']['manager']['user']), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Accounts::userRowByUuid($t['a']['manager']['user'])['deleted_at'])->toBeNull();
});

it('answers 405 to another method than DELETE [NFR-SEC-01] (scenario 11)', function (string $method) {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->other($method, removeUrl($t['a']['manager']['user']));

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect(array_map('trim', explode(',', (string) $response->headers->get('Allow'))))->toContain('DELETE');
})->with(['GET', 'POST', 'PUT', 'PATCH']);
