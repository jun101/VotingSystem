<?php

/*
 * POST /api/v1/platform/institutions/{institution}/impersonate and DELETE /api/v1/platform/impersonation —
 * docs/api/platform/POST-platform-institutions-{institution}-impersonate.md, DELETE-platform-impersonation.md.
 * Jun, 2026-10-12: the platform admin may act as a school's owner, without the school's consent.
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Team;
use Tests\Support\Voting;

require_once __DIR__.'/ListInstitutionsTest.php';

function impersonateUrl(string $uuid): string
{
    return "/api/v1/platform/institutions/{$uuid}/impersonate";
}

const END_IMPERSONATION = '/api/v1/platform/impersonation';

/** Two institutions, the admin signed in. Returns the team. */
function impersonationSetup($test): array
{
    $t = Team::two();
    Voting::election(['institution' => $t['a']['owner']['institution'], 'status' => 'draft']);
    platformSignedIn($test);

    return $t;
}

it('lets the admin act as the institution\'s owner and no other institution [FR-ADM-01] (scenario 1)', function () {
    $t = impersonationSetup($this);
    $a = $t['a']['owner']['institution'];

    $response = $this->browser->post(impersonateUrl($a))->assertOk();

    expect($response->json('data'))->toMatchArray(['role' => 'owner'])
        ->and($response->json('data.institution.id'))->toBe($a)
        ->and($response->json('data.impersonating.by'))->toBe('Admin Plateforme');
    $me = $this->browser->get('/api/v1/auth/me')->assertOk();
    expect($me->json('data.role'))->toBe('owner')->and($me->json('data.impersonating.by'))->toBe('Admin Plateforme');
    $elections = $this->browser->get('/api/v1/elections')->assertOk()->json('data');
    expect($elections)->toHaveCount(1);
    $this->browser->post('/api/v1/elections', ['title' => 'Créée par la plateforme', 'starts_at' => '2027-10-12T12:00:00Z', 'ends_at' => '2027-10-16T19:00:00Z'])->assertStatus(201);
});

it('names the admin on every entry written while acting, visible to the owner [FR-AUD-01] (scenario 1)', function () {
    $t = impersonationSetup($this);
    $a = $t['a']['owner']['institution'];
    $this->browser->post(impersonateUrl($a))->assertOk();
    $this->browser->post('/api/v1/elections', ['title' => 'Créée par la plateforme', 'starts_at' => '2027-10-12T12:00:00Z', 'ends_at' => '2027-10-16T19:00:00Z'])->assertStatus(201);
    $this->browser->delete(END_IMPERSONATION)->assertOk();

    $owner = new AuthClient($this);
    $owner->login($t['a']['owner']['email'], $t['a']['owner']['password'])->assertOk();
    $entries = collect($owner->get('/api/v1/audit')->assertOk()->json('data'));
    $created = $entries->firstWhere('action', 'election.created');

    expect($created['actor']['id'])->toBeNull()
        ->and($created['actor']['name'])->toBe('Admin Plateforme (plateforme)')
        ->and($created['details']['impersonated_by'])->toBe('Admin Plateforme')
        ->and($entries->pluck('action'))->toContain('platform.impersonation_started')->toContain('platform.impersonation_ended');
});

it('has the owner\'s whole power, account security included, and the audit names the admin [FR-ADM-01] (scenario 1)', function () {
    $t = impersonationSetup($this);
    $this->browser->post(impersonateUrl($t['a']['owner']['institution']))->assertOk();

    $this->browser->delete('/api/v1/users/'.$t['a']['manager']['user'])->assertStatus(204);
    $this->browser->patch('/api/v1/institution', ['name' => 'Nom changé par la plateforme'])->assertOk();

    expect(Accounts::userRowByUuid($t['a']['manager']['user'])['deleted_at'])->not->toBeNull()
        ->and(Accounts::institutionRow($t['a']['owner']['institution'])['name'])->toBe('Nom changé par la plateforme')
        ->and(Accounts::mail())->toBe([]);
});

it('ends on request, and the admin is only an admin again [FR-ADM-01] (DELETE scenario 1)', function () {
    $t = impersonationSetup($this);
    $this->browser->post(impersonateUrl($t['a']['owner']['institution']))->assertOk();

    $this->browser->delete(END_IMPERSONATION)->assertOk()->assertJsonPath('data.role', 'platform_admin');

    $this->browser->get('/api/v1/auth/me')->assertOk()->assertJsonPath('data.role', 'platform_admin')->assertJsonMissingPath('data.impersonating');
    expect($this->browser->get('/api/v1/elections')->getStatusCode())->toBeIn([403, 404]);
});

it('answers 403 institution_suspended for a suspended institution, 404 for an unknown one [FR-INST-06] (scenarios 2, 3)', function () {
    $t = impersonationSetup($this);
    Accounts::suspend($t['b']['owner']['institution']);

    $this->browser->post(impersonateUrl($t['b']['owner']['institution']))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    $unknown = Team::shape($this->browser->post(impersonateUrl('3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa')));
    expect($unknown['status'])->toBe(404)
        ->and(Team::shape($this->browser->post(impersonateUrl('not-a-uuid'))))->toBe($unknown);
});

it('answers 403 to an owner, even for their own institution [FR-ADM-02] (scenario 4)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $this->browser->post(impersonateUrl($t['a']['owner']['institution']))->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('answers 409 already_impersonating the second time, and not_impersonating to end when not acting [FR-ADM-01] (scenario 5)', function () {
    $t = impersonationSetup($this);
    $this->browser->delete(END_IMPERSONATION)->assertStatus(409)->assertJsonPath('error.code', 'not_impersonating');
    $this->browser->post(impersonateUrl($t['a']['owner']['institution']))->assertOk();

    $this->browser->post(impersonateUrl($t['b']['owner']['institution']))->assertStatus(409)->assertJsonPath('error.code', 'already_impersonating');
});

it('answers 401 signed out, 419 without the token and 405 for another method [NFR-SEC-04] (scenario 6)', function () {
    $t = Team::two();
    (new AuthClient($this))->post(impersonateUrl($t['a']['owner']['institution']))->assertStatus(401);

    platformSignedIn($this);
    $this->browser->post(impersonateUrl($t['a']['owner']['institution']), [], [], false)->assertStatus(419);
    $this->browser->other('GET', impersonateUrl($t['a']['owner']['institution']))->assertStatus(405);
    $this->browser->other('GET', END_IMPERSONATION)->assertStatus(405);
});

it('keeps the ballot secret from the admin as from the owner [rule 3]', function () {
    $e = Voting::election();
    platformSignedIn($this);
    $this->browser->post(impersonateUrl($e['institution']))->assertOk();

    $response = $this->browser->get("/api/v1/elections/{$e['election']}/results");

    expect($response->getStatusCode())->toBe(409)->and($response->getContent())->not->toContain('votes_count');
});
