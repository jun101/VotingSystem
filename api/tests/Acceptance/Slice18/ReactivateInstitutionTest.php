<?php

/*
 * POST /api/v1/platform/institutions/{institution}/reactivate — docs/api/platform/POST-platform-institutions-{institution}-reactivate.md
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Team;

require_once __DIR__.'/SuspendInstitutionTest.php';

function suspendedByAdmin($test, string $language = 'fr'): array
{
    $t = Team::two();
    Accounts::updateInstitution($t['a']['owner']['institution'], ['language' => $language]);
    Accounts::updateInstitution($t['a']['owner']['institution'], ['suspended_at' => now('UTC')->subHour()->toDateTimeString(), 'suspension_reason' => 'Paiement non reçu']);
    platformSignedIn($test);

    return $t;
}

it('reactivates a suspended institution, clears the reason and lets its users in again [FR-ADM-01, FR-INST-06] (scenario 1)', function () {
    $t = suspendedByAdmin($this);

    $response = $this->browser->post(reactivateUrl($t['a']['owner']['institution']))->assertOk();

    $row = Accounts::institutionRow($t['a']['owner']['institution']);
    expect($response->json('data'))->toMatchArray(['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null])
        ->and($row['suspended_at'])->toBeNull()
        ->and($row['suspension_reason'])->toBeNull();
    $owner = new AuthClient($this);
    $owner->login($t['a']['owner']['email'], $t['a']['owner']['password'])->assertOk();
});

it('emails every owner without the reason, and writes the audit entry with the lifted reason [FR-INST-06, FR-AUD-01] (scenario 1)', function () {
    $t = suspendedByAdmin($this, 'en');
    $a = $t['a']['owner']['institution'];

    $this->browser->post(reactivateUrl($a))->assertOk();

    foreach (['owner', 'owner2'] as $who) {
        $mail = Accounts::mailTo($t['a'][$who]['email']);
        expect($mail)->toHaveCount(1)->and($mail[0]['text'])->not->toContain('Paiement non reçu')
            ->and(strtolower($mail[0]['subject']))->toContain('reactivated');
    }
    expect(Accounts::mailTo($t['a']['manager']['email']))->toBe([]);
    $entry = platformAudit('institution.reactivated', $a)[0];
    expect($entry['institution_id'])->toBeNull()->and(json_decode($entry['details'], true))->toBe(['reason' => 'Paiement non reçu']);
});

it('answers 409 not_suspended for an active institution [FR-ADM-01] (scenario 2)', function () {
    $t = Team::two();
    platformSignedIn($this);

    $this->browser->post(reactivateUrl($t['a']['owner']['institution']))->assertStatus(409)->assertJsonPath('error.code', 'not_suspended');
});

it('answers 404 for an unknown institution and a value that is not a UUID, the same [NFR-SEC-04] (scenario 3)', function () {
    platformSignedIn($this);

    $unknown = Team::shape($this->browser->post(reactivateUrl(SUSPEND_UNKNOWN)));
    expect($unknown['status'])->toBe(404);
    foreach (['not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(reactivateUrl($target))))->toBe($unknown);
    }
});

it('answers 403 to an owner of the suspended institution and a user of another one [FR-ADM-02] (scenario 4)', function () {
    $t = Team::two();
    Accounts::suspend($t['b']['owner']['institution']);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->post(reactivateUrl($t['b']['owner']['institution']))->assertStatus(403)->assertJsonPath('error.code', 'forbidden');

    expect(Accounts::institutionRow($t['b']['owner']['institution'])['suspended_at'])->not->toBeNull();
});

it('answers 401 signed out, 419 without the CSRF token and 405 for another method [NFR-SEC-04] (scenarios 5, 6, 8)', function () {
    $t = suspendedByAdmin($this);
    $a = $t['a']['owner']['institution'];

    $this->browser->post(reactivateUrl($a), [], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('GET', reactivateUrl($a))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    (new AuthClient($this))->post(reactivateUrl($a))->assertStatus(401);
});

it('answers 429 above 60 requests an hour [NFR-SEC-05] (scenario 7)', function () {
    $t = suspendedByAdmin($this);

    for ($i = 0; $i < 60; $i++) {
        $this->browser->post(reactivateUrl($t['a']['owner']['institution']));
    }

    $this->browser->post(reactivateUrl($t['a']['owner']['institution']))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
