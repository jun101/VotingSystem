<?php

/*
 * GET /api/v1/platform/institutions — docs/api/platform/GET-platform-institutions.md
 * Platform route: a platform admin only; the answer is counts, never a tenant's records.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;
use Tests\Support\Voting;

function platformAdmin(): array
{
    return Accounts::user(['role' => 'platform_admin', 'name' => 'Admin Plateforme', 'email' => 'admin@platform.test']);
}

function platformSignedIn($test): array
{
    $admin = platformAdmin();
    Team::signIn($test, $admin);

    return $admin;
}

it('lists the institutions with their status and counts, newest created first [FR-ADM-01] (scenario 1)', function () {
    $t = Team::two();
    $a = $t['a']['owner']['institution'];
    Accounts::updateInstitution($a, ['name' => 'Collège Étoile du Matin', 'created_at' => '2026-09-01 09:00:00']);
    Accounts::updateInstitution($t['b']['owner']['institution'], ['name' => 'Lycée Boyer', 'created_at' => '2026-09-05 09:00:00']);
    Voting::election(['institution' => $a, 'status' => 'open']);
    Voting::election(['institution' => $a, 'status' => 'closed']);
    Voting::election(['institution' => $a, 'status' => 'draft']);
    platformSignedIn($this);

    $response = $this->browser->get('/api/v1/platform/institutions')->assertOk();
    $rows = collect($response->json('data'))->keyBy('id');

    expect($response->json('data.0.id'))->toBe($t['b']['owner']['institution'])
        ->and($rows[$a])->toMatchArray(['name' => 'Collège Étoile du Matin', 'status' => 'active', 'suspended_at' => null, 'suspension_reason' => null])
        ->and($rows[$a]['counts']['users'])->toBe(3)
        ->and($rows[$a]['counts']['voters'])->toBe(9)
        ->and($rows[$a]['counts']['elections'])->toBe(['draft' => 1, 'scheduled' => 0, 'open' => 1, 'closed' => 1, 'published' => 0, 'archived' => 0])
        ->and($response->json('meta'))->toMatchArray(['page' => 1, 'per_page' => 20]);
});

it('shows the status and the reason of a suspended institution [FR-ADM-01] (scenario 1)', function () {
    $t = Team::two();
    Accounts::updateInstitution($t['a']['owner']['institution'], ['suspended_at' => '2026-10-12 14:00:00', 'suspension_reason' => 'Paiement non reçu']);
    platformSignedIn($this);

    $row = collect($this->browser->get('/api/v1/platform/institutions')->assertOk()->json('data'))->firstWhere('id', $t['a']['owner']['institution']);

    expect($row)->toMatchArray(['status' => 'suspended', 'suspended_at' => '2026-10-12T14:00:00Z', 'suspension_reason' => 'Paiement non reçu']);
});

it('filters by name and by status [FR-ADM-01] (scenario 2)', function () {
    $t = Team::two();
    Accounts::updateInstitution($t['a']['owner']['institution'], ['name' => 'Collège Étoile du Matin']);
    Accounts::updateInstitution($t['b']['owner']['institution'], ['name' => 'Lycée Boyer']);
    Accounts::suspend($t['b']['owner']['institution']);
    platformSignedIn($this);

    $byName = $this->browser->get('/api/v1/platform/institutions?q=etoile')->assertOk();
    $byAccent = $this->browser->get('/api/v1/platform/institutions?q='.rawurlencode('étoile'))->assertOk();
    $suspended = $this->browser->get('/api/v1/platform/institutions?status=suspended')->assertOk();
    $both = $this->browser->get('/api/v1/platform/institutions?status=active&q=boyer')->assertOk();

    expect(array_column($byAccent->json('data'), 'id'))->toBe([$t['a']['owner']['institution']])
        ->and(array_column($suspended->json('data'), 'id'))->toBe([$t['b']['owner']['institution']])
        ->and($suspended->json('meta.total'))->toBe(1)
        ->and($both->json('data'))->toBe([])
        ->and($byName->json('meta.total'))->toBeInt();
});

it('answers an empty list when nothing matches [FR-ADM-01] (scenario 3)', function () {
    Team::two();
    platformSignedIn($this);

    $response = $this->browser->get('/api/v1/platform/institutions?q=zzzzzz')->assertOk();

    expect($response->json('data'))->toBe([])->and($response->json('meta.total'))->toBe(0);
});

it('answers 422 for a bad status, per_page or page [FR-ADM-01] (scenario 4)', function (string $query, string $field) {
    platformSignedIn($this);

    $response = $this->browser->get('/api/v1/platform/institutions?'.$query)->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')->and($response->json('error.fields'))->toHaveKey($field);
})->with([
    'status' => ['status=gone', 'status'],
    'per_page 0' => ['per_page=0', 'per_page'],
    'per_page 101' => ['per_page=101', 'per_page'],
    'page 0' => ['page=0', 'page'],
]);

it('answers 403 to an owner and to a manager [FR-ADM-02] (scenario 5)', function (string $who) {
    $t = Team::two();
    Team::signIn($this, $t['a'][$who]);

    $this->browser->get('/api/v1/platform/institutions')->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
})->with(['owner', 'manager']);

it('answers 401 when signed out and 405 for another method [NFR-SEC-04] (scenario 6)', function () {
    $this->browser->get('/api/v1/platform/institutions')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    platformSignedIn($this);
    $this->browser->other('PUT', '/api/v1/platform/institutions')->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
});

it('gives counts only: no user, voter, code, candidate, title or numeric id [FR-ADM-02, NFR-SEC-08]', function () {
    $t = Team::two();
    $e = Voting::election(['institution' => $t['a']['owner']['institution']]);
    platformSignedIn($this);

    $response = $this->browser->get('/api/v1/platform/institutions')->assertOk();
    $body = $response->getContent();

    foreach ($response->json('data') as $row) {
        expect(array_keys($row))->toBe(['id', 'name', 'type', 'status', 'suspended_at', 'suspension_reason', 'created_at', 'last_sign_in_at', 'counts']);
    }
    foreach (['Wideline', 'Paul Étienne', 'Candidat A', 'Conseil des élèves', 'alice.a@example.test', $e['codes']['Wideline'], $e['election']] as $secret) {
        expect($body)->not->toContain($secret);
    }
    expect($body)->not->toContain('"institution_id"')->and($body)->not->toContain('"user_id"');
});
