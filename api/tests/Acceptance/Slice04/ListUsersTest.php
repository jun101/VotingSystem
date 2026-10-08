<?php

/*
 * GET /api/v1/users — docs/api/users/GET-users.md
 * Tenant suite, route api/v1/users: another institution's users are never listed (scenario 2),
 * and a manager of this institution is refused (scenario 4).
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\Team;

const USERS = '/api/v1/users';

it('lists the users of the institution, owners first then by name, with the shape of the file [FR-INST-03] (scenario 1)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->get(USERS)->assertOk();

    expect(array_keys($response->json()))->toBe(['data', 'meta'])
        ->and($response->json('meta'))->toBe(['page' => 1, 'per_page' => 25, 'total' => 3])
        ->and(array_column($response->json('data'), 'name'))->toBe(['Alice Owner A', 'Aline Second A', 'Armand Manager A'])
        ->and(array_column($response->json('data'), 'role'))->toBe(['owner', 'owner', 'manager'])
        ->and(array_keys($response->json('data.0')))->toEqualCanonicalizing(['id', 'name', 'email', 'role', 'email_verified', 'last_login_at', 'two_factor_enabled', 'is_you'])
        ->and($response->json('data.0'))->toMatchArray(['id' => $t['a']['owner']['user'], 'email' => 'alice.a@example.test', 'email_verified' => true, 'is_you' => true])
        ->and($response->json('data.1.is_you'))->toBeFalse()
        ->and($response->json('data.0.id'))->toMatch(UUID_V4);

    expect($response->getContent())->not->toContain('password')->not->toContain('institution_id')->not->toContain('two_factor');
});

it('shows last_login_at in UTC and null before the first sign-in [FR-INST-03] (scenario 1)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);   // sets the owner's last_login_at

    $response = $this->browser->get(USERS)->assertOk();

    expect($response->json('data.0.last_login_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($response->json('data.2.last_login_at'))->toBeNull();
});

it('lists only this institution\'s users, and a second owner sees them too [FR-INST-05] (scenario 2)', function () {
    $t = Team::two();
    Team::signIn($this, $t['b']['owner']);

    $response = $this->browser->get(USERS)->assertOk();

    expect(array_column($response->json('data'), 'id'))->toEqualCanonicalizing([$t['b']['owner']['user'], $t['b']['manager']['user']])
        ->and($response->json('meta.total'))->toBe(2)
        ->and($response->getContent())->not->toContain('alice.a@example.test');
});

it('does not list a removed user and does not count it [FR-INST-03] (scenario 3)', function () {
    $t = Team::two();
    Accounts::user(['role' => 'manager', 'institution' => $t['a']['owner']['institution'], 'removed' => true, 'name' => 'Rémy Removed']);
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->get(USERS)->assertOk();

    expect($response->json('meta.total'))->toBe(3)
        ->and($response->getContent())->not->toContain('Rémy');
});

it('paginates [FR-INST-03] (scenario 1)', function () {
    $t = Team::two();
    foreach (range(1, 4) as $i) {
        Accounts::user(['role' => 'manager', 'institution' => $t['a']['owner']['institution'], 'name' => "Gestionnaire {$i}"]);
    }
    Team::signIn($this, $t['a']['owner']);

    $page = $this->browser->get(USERS.'?per_page=5&page=2')->assertOk();

    expect($page->json('meta'))->toBe(['page' => 2, 'per_page' => 5, 'total' => 7])
        ->and($page->json('data'))->toHaveCount(2);
});

it('answers 403 to a manager [FR-INST-03] (scenario 4)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['manager']);

    $response = $this->browser->get(USERS);

    $response->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    expect($response->getContent())->not->toContain('alice.a@example.test');
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-03] (scenario 5)', function () {
    $this->browser->get(USERS)->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 6)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->get(USERS)->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 422 for a page size or a page out of range [FR-INST-03] (scenario 7)', function (string $query, string $field) {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->get(USERS.$query);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields'))->toHaveKey($field);
})->with([['?per_page=101', 'per_page'], ['?per_page=0', 'per_page'], ['?page=0', 'page'], ['?per_page=abc', 'per_page']]);

it('answers 405 to another method than GET or HEAD [NFR-SEC-01] (scenario 8)', function (string $method) {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->other($method, USERS);

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $allow = array_map('trim', explode(',', (string) $response->headers->get('Allow')));
    expect($allow)->toContain('GET')->not->toContain($method);
})->with(['POST', 'PUT', 'PATCH']);
