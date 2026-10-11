<?php

/*
 * GET /api/v1/generated-files/{file}/download — docs/api/credentials/GET-generated-files-{file}-download.md
 * Tenant suite, route api/v1/generated-files/{file}/download: another institution's file answers 404, the same as an
 * unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Pdfs;
use Tests\Support\Team;

const DOWNLOAD_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function downloadUrl(string $uuid): string
{
    return "/api/v1/generated-files/{$uuid}/download";
}

/** An owner of A signed in and an election of the given status. Returns [team, election]. */
function downloadSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('streams the decrypted PDF with a fixed name, no cache and no sniffing [FR-CRED-05] (scenario 1)', function () {
    [$t, $election] = downloadSignedIn($this);
    $group = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $pdf = Pdfs::sample('K7MP4-QX9RT');
    $file = Accounts::plantGeneratedFile(['election' => $election, 'group' => $group, 'pdf' => $pdf]);

    $response = $this->browser->get(downloadUrl($file))->assertOk();

    expect($response->getContent())->toBe($pdf)
        ->and($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="coupons-4e-annee.pdf"')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('names the file of the voters with no group, and a part [FR-CRED-05] (scenario 1)', function () {
    [$t, $election] = downloadSignedIn($this);
    $group = Accounts::plantGroup(['election' => $election, 'name' => 'Grands élèves & co']);
    $none = Accounts::plantGeneratedFile(['election' => $election]);
    $part = Accounts::plantGeneratedFile(['election' => $election, 'group' => $group, 'part' => 2, 'parts' => 3]);

    expect($this->browser->get(downloadUrl($none))->assertOk()->headers->get('Content-Disposition'))->toBe('attachment; filename="coupons-sans-groupe.pdf"')
        ->and($this->browser->get(downloadUrl($part))->assertOk()->headers->get('Content-Disposition'))->toBe('attachment; filename="coupons-grands-eleves-co-partie-2.pdf"');
});

it('works in a scheduled and an open election [FR-CRED-05] (scenario 1)', function (string $status) {
    [$t, $election] = downloadSignedIn($this, $status);
    $file = Accounts::plantGeneratedFile(['election' => $election]);

    $this->browser->get(downloadUrl($file))->assertOk();
})->with(['scheduled', 'open']);

it('answers 409 file_not_ready for a queued or failed file [FR-CRED-05] (scenario 2)', function (string $status) {
    [$t, $election] = downloadSignedIn($this);
    $file = Accounts::plantGeneratedFile(['election' => $election, 'status' => $status]);

    $this->browser->get(downloadUrl($file))->assertStatus(409)->assertJsonPath('error.code', 'file_not_ready');
})->with(['queued', 'failed']);

it('answers 404 once the election is closed, published or archived [FR-CRED-05] (scenario 3)', function (string $status) {
    [$t, $election] = downloadSignedIn($this, $status);
    $file = Accounts::plantGeneratedFile(['election' => $election]);

    $this->browser->get(downloadUrl($file))->assertStatus(404)->assertJsonPath('error.code', 'not_found');
})->with(['closed', 'published', 'archived']);

it('answers 404, the same for every case, for a file that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 4)', function () {
    [$t, $election] = downloadSignedIn($this);
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantGeneratedFile(['election' => $foreignElection, 'user' => $t['b']['owner']['user']]);

    $unknown = Team::shape($this->browser->get(downloadUrl(DOWNLOAD_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(downloadUrl($target))))->toBe($unknown);
    }
});

it('answers 401, 403 and 405 as every read [NFR-SEC-04] (scenarios 5 to 7)', function () {
    [$t, $election] = downloadSignedIn($this);
    $file = Accounts::plantGeneratedFile(['election' => $election]);

    $this->browser->other('POST', downloadUrl($file))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->get(downloadUrl($file))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 401 when nobody is signed in [FR-CRED-05] (scenario 5)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $file = Accounts::plantGeneratedFile(['election' => $election]);

    $this->browser->get(downloadUrl($file))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 429 above 120 downloads an hour from one user [NFR-SEC-05] (scenario 6)', function () {
    [$t, $election] = downloadSignedIn($this);
    $file = Accounts::plantGeneratedFile(['election' => $election]);

    foreach (range(1, 120) as $i) {
        $this->browser->get(downloadUrl($file))->assertOk();
    }

    $this->browser->get(downloadUrl($file))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
