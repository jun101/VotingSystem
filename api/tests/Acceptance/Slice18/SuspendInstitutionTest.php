<?php

/*
 * POST /api/v1/platform/institutions/{institution}/suspend — docs/api/platform/POST-platform-institutions-{institution}-suspend.md
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Team;

require_once __DIR__.'/ListInstitutionsTest.php';

const SUSPEND_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function suspendUrl(string $uuid): string
{
    return "/api/v1/platform/institutions/{$uuid}/suspend";
}

function reactivateUrl(string $uuid): string
{
    return "/api/v1/platform/institutions/{$uuid}/reactivate";
}

/** The platform's audit entries of an action for an institution (newest first). */
function platformAudit(string $action, string $institution): array
{
    return DB::connection(useMigratorConnection())->table('audit_entries')
        ->where('action', $action)->where('subject_uuid', $institution)->orderByDesc('id')->get()
        ->map(fn ($r) => (array) $r)->all();
}

it('suspends an active institution with a reason [FR-ADM-01, FR-INST-06] (scenario 1)', function () {
    $t = Team::two();
    $a = $t['a']['owner']['institution'];
    $admin = platformSignedIn($this);

    $response = $this->browser->post(suspendUrl($a), ['reason' => '  Paiement non reçu '])->assertOk();

    $row = Accounts::institutionRow($a);
    expect($response->json('data'))->toMatchArray(['id' => $a, 'status' => 'suspended', 'suspension_reason' => 'Paiement non reçu'])
        ->and($row['suspended_at'])->not->toBeNull()
        ->and($row['suspension_reason'])->toBe('Paiement non reçu');
});

it('refuses the institution\'s users at once, with the reason at sign-in only [FR-INST-06] (scenario 1)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);
    $owner = $this->browser;
    $this->browser = new AuthClient($this);
    platformSignedIn($this);
    $this->browser->post(suspendUrl($t['a']['owner']['institution']), ['reason' => 'Paiement non reçu'])->assertOk();

    $owner->get('/api/v1/auth/me')->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended')->assertJsonMissingPath('error.reason');
    $fresh = new AuthClient($this);
    $fresh->login($t['a']['manager']['email'], $t['a']['manager']['password'])
        ->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended')->assertJsonPath('error.reason', 'Paiement non reçu');
    // The other institution is not touched.
    $b = new AuthClient($this);
    $b->login($t['b']['owner']['email'], $t['b']['owner']['password'])->assertOk();
});

it('gives the reason only with the right password [FR-INST-06] (scenario 1)', function () {
    $t = Team::two();
    Accounts::updateInstitution($t['a']['owner']['institution'], ['suspended_at' => now('UTC')->toDateTimeString(), 'suspension_reason' => 'Secret du jour']);

    $response = $this->browser->login($t['a']['owner']['email'], 'not the password at all')->assertStatus(401);

    expect($response->getContent())->not->toContain('Secret du jour');
});

it('emails every owner, not the managers, in the institution\'s language, with the reason [FR-INST-06] (scenario 1)', function () {
    $t = Team::two();
    $a = $t['a']['owner']['institution'];
    Accounts::updateInstitution($a, ['language' => 'en']);
    platformSignedIn($this);

    $this->browser->post(suspendUrl($a), ['reason' => 'Unpaid invoice'])->assertOk();

    foreach (['owner', 'owner2'] as $who) {
        $mail = Accounts::mailTo($t['a'][$who]['email']);
        expect($mail)->toHaveCount(1)
            ->and($mail[0]['text'])->toContain('Unpaid invoice')
            ->and(strtolower($mail[0]['subject']))->toContain('suspended');
    }
    expect(Accounts::mailTo($t['a']['manager']['email']))->toBe([])
        ->and(Accounts::mailTo($t['b']['owner']['email']))->toBe([]);
});

it('writes a platform audit entry that the owner\'s log does not show [FR-AUD-01] (scenario 1)', function () {
    $t = Team::two();
    $a = $t['a']['owner']['institution'];
    $admin = platformSignedIn($this);

    $this->browser->post(suspendUrl($a), ['reason' => 'Paiement non reçu'])->assertOk();

    $entry = platformAudit('institution.suspended', $a)[0];
    expect($entry['institution_id'])->toBeNull()
        ->and($entry['actor_name'])->toBe('Admin Plateforme')
        ->and($entry['subject_type'])->toBe('institution')
        ->and(json_decode($entry['details'], true))->toBe(['reason' => 'Paiement non reçu']);

    Accounts::updateInstitution($a, ['suspended_at' => null, 'suspension_reason' => null]);
    $owner = new AuthClient($this);
    $owner->login($t['a']['owner']['email'], $t['a']['owner']['password'])->assertOk();
    $actions = array_column($owner->get('/api/v1/audit')->assertOk()->json('data'), 'action');
    expect($actions)->not->toContain('institution.suspended');
});

it('answers 422 for a missing, empty, short or long reason, and changes nothing [FR-ADM-01] (scenario 2)', function (array $body, string $rule) {
    $t = Team::two();
    platformSignedIn($this);

    $response = $this->browser->post(suspendUrl($t['a']['owner']['institution']), $body)->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')
        ->and($response->json('error.fields.reason'))->toContain($rule)
        ->and(Accounts::institutionRow($t['a']['owner']['institution'])['suspended_at'])->toBeNull();
})->with([
    'missing' => [[], 'required'],
    'empty' => [['reason' => ''], 'required'],
    'spaces' => [['reason' => "   \n "], 'required'],
    'two characters' => [['reason' => 'ab'], 'min'],
    'too long' => [['reason' => str_repeat('a', 501)], 'max'],
]);

it('removes control characters from the reason [FR-ADM-01] (scenario 1)', function () {
    $t = Team::two();
    platformSignedIn($this);

    $this->browser->post(suspendUrl($t['a']['owner']['institution']), ['reason' => "Paie\x07ment \u{202E}non"])->assertOk()->assertJsonPath('data.suspension_reason', 'Paiement non');
});

it('answers 409 already_suspended and keeps the first reason [FR-ADM-01] (scenario 3)', function () {
    $t = Team::two();
    platformSignedIn($this);
    $this->browser->post(suspendUrl($t['a']['owner']['institution']), ['reason' => 'Premier motif'])->assertOk();

    $this->browser->post(suspendUrl($t['a']['owner']['institution']), ['reason' => 'Second motif'])->assertStatus(409)->assertJsonPath('error.code', 'already_suspended');

    expect(Accounts::institutionRow($t['a']['owner']['institution'])['suspension_reason'])->toBe('Premier motif');
});

it('answers 404 for an unknown institution and a value that is not a UUID, the same [NFR-SEC-04] (scenario 4)', function () {
    platformSignedIn($this);

    $unknown = Team::shape($this->browser->post(suspendUrl(SUSPEND_UNKNOWN), ['reason' => 'Motif valide']));
    expect($unknown['status'])->toBe(404);
    foreach (['not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(suspendUrl($target), ['reason' => 'Motif valide'])))->toBe($unknown);
    }
});

it('answers 403 to an owner, even for their own institution [FR-ADM-02] (scenario 5)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $this->browser->post(suspendUrl($t['a']['owner']['institution']), ['reason' => 'Motif valide'])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');

    expect(Accounts::institutionRow($t['a']['owner']['institution'])['suspended_at'])->toBeNull();
});

it('answers 401 signed out and 419 without the CSRF token [NFR-SEC-04] (scenarios 6, 7)', function () {
    $t = Team::two();
    $this->browser->post(suspendUrl($t['a']['owner']['institution']), ['reason' => 'Motif valide'])->assertStatus(401);

    platformSignedIn($this);
    $this->browser->post(suspendUrl($t['a']['owner']['institution']), ['reason' => 'Motif valide'], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
});

it('answers 429 above 60 requests an hour, suspend and reactivate counted together [NFR-SEC-05] (scenario 8)', function () {
    $t = Team::two();
    $a = $t['a']['owner']['institution'];
    platformSignedIn($this);

    for ($i = 0; $i < 30; $i++) {
        $this->browser->post(suspendUrl($a), ['reason' => 'Motif valide'])->assertOk();
        $this->browser->post(reactivateUrl($a))->assertOk();
    }

    $this->browser->post(suspendUrl($a), ['reason' => 'Motif valide'])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});

it('answers 405 for another method and 400 for a body that is not JSON [NFR-SEC-04] (scenario 9)', function () {
    $t = Team::two();
    platformSignedIn($this);

    $this->browser->other('GET', suspendUrl($t['a']['owner']['institution']))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $this->browser->postRaw(suspendUrl($t['a']['owner']['institution']), '{not json')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});
