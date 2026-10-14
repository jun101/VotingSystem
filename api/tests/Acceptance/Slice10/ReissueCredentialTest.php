<?php

/*
 * POST /api/v1/voters/{voter}/credential — docs/api/credentials/POST-voters-{voter}-credential.md
 * Tenant suite, route api/v1/voters/{voter}/credential: another institution's voter answers 404, the same as an
 * unknown one. The refusal for a voter who has voted (scenario 5) is tested in slice 12.
 */

use Illuminate\Support\Facades\Crypt;
use Tests\Support\Accounts;
use Tests\Support\Codes;
use Tests\Support\Pdfs;
use Tests\Support\Team;

const REISSUE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function reissueUrl(string $uuid): string
{
    return "/api/v1/voters/{$uuid}/credential";
}

/** An owner of A signed in, an election of the given status, a group and one voter in it. Returns [team, election, voter, group]. */
function reissueSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status, 'title' => 'Conseil des élèves']);
    $group = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Wideline Joseph', 'group' => $group]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $voter, $group];
}

it('gives a first code to a voter who has none, shown once with a ready one-slip file [FR-CRED-01, FR-CRED-02] (scenarios 1, 3)', function () {
    [$t, $election, $voter, $group] = reissueSignedIn($this);

    $response = $this->browser->post(reissueUrl($voter))->assertCreated();

    $data = $response->json('data');
    expect($data['code'])->toMatch(Codes::PATTERN)
        ->and($data['reissue_count'])->toBe(0)
        ->and($data['voter'])->toBe(['id' => $voter, 'full_name' => 'Wideline Joseph', 'group' => ['id' => $group, 'name' => '4e année']])
        ->and($data['file'])->toMatchArray(['kind' => 'slips_pdf', 'voter_count' => 1, 'status' => 'ready', 'part' => 1, 'parts' => 1])
        ->and($data['file']['group'])->toBe(['id' => $group, 'name' => '4e année'])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    expect(Accounts::credentialRow($voter)['code_hash'])->toBe(Codes::hash($data['code']))
        ->and(Accounts::credentialRow($voter)['issued_at'])->not->toBeNull();
});

it('replaces the code of a voter who has one: the old hash is gone and the count goes up [FR-CRED-03] (scenarios 2, 4)', function () {
    [$t, $election, $voter] = reissueSignedIn($this);
    Accounts::plantCredential(['voter' => $voter, 'code' => 'K7MP4-QX9RT']);

    $response = $this->browser->post(reissueUrl($voter))->assertCreated();

    $new = $response->json('data.code');
    $row = Accounts::credentialRow($voter);
    expect($new)->not->toBe('K7MP4-QX9RT')
        ->and($row['code_hash'])->toBe(Codes::hash($new))
        ->and($row['code_hash'])->not->toBe(Codes::hash('K7MP4-QX9RT'))
        ->and($row['reissue_count'])->toBe(1)
        ->and($response->json('data.reissue_count'))->toBe(1)
        ->and(Accounts::credentialRows($election))->toHaveCount(1);
    $second = $this->browser->post(reissueUrl($voter))->assertCreated();
    expect(Accounts::credentialRow($voter)['reissue_count'])->toBe(2)
        ->and($second->json('data.code'))->not->toBe($new);
});

it('writes a one-slip PDF with the voter, the group and the new code, kept like any other file [FR-CRED-04, FR-CRED-05] (scenario 3)', function () {
    [$t, $election, $voter, $group] = reissueSignedIn($this);

    $response = $this->browser->post(reissueUrl($voter))->assertCreated();

    $fileId = $response->json('data.file.id');
    $rows = Accounts::generatedFileRows($election);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['uuid'])->toBe($fileId)
        ->and($rows[0]['voter_count'])->toBe(1);
    $pdf = Crypt::decryptString(file_get_contents($this->credentialsRoot.'/'.$rows[0]['stored_file']));
    expect(Pdfs::isLetter($pdf))->toBeTrue()
        ->and(Pdfs::pageCount($pdf))->toBe(1)
        ->and(Pdfs::codesByName($pdf, ['Wideline Joseph']))->toBe(['Wideline Joseph' => $response->json('data.code')]);
    $this->browser->get("/api/v1/generated-files/{$fileId}/download")->assertOk();
});

it('does not touch the code of another voter [FR-CRED-03] (scenario 2)', function () {
    [$t, $election, $voter, $group] = reissueSignedIn($this);
    $other = Accounts::plantVoter(['election' => $election, 'full_name' => 'Autre', 'group' => $group]);
    Accounts::plantCredential(['voter' => $other, 'code' => 'ABCDE-FGHJK']);

    $this->browser->post(reissueUrl($voter))->assertCreated();

    expect(Accounts::credentialRow($other)['code_hash'])->toBe(Codes::hash('ABCDE-FGHJK'))
        ->and(Accounts::credentialRow($other)['reissue_count'])->toBe(0);
});

it('keeps the old code when the file cannot be written and answers 500 [FR-CRED-03] (scenario 7)', function () {
    [$t, $election, $voter] = reissueSignedIn($this);
    Accounts::plantCredential(['voter' => $voter, 'code' => 'K7MP4-QX9RT']);
    Codes::breakDisk();

    $this->browser->post(reissueUrl($voter))->assertStatus(500);

    expect(Accounts::credentialRow($voter)['code_hash'])->toBe(Codes::hash('K7MP4-QX9RT'))
        ->and(Accounts::credentialRow($voter)['reissue_count'])->toBe(0)
        ->and(Accounts::generatedFileRows($election))->toBe([]);
});

it('works in a draft, scheduled or open election [FR-CRED-03] (scenario 1)', function (string $status) {
    [$t, $election, $voter] = reissueSignedIn($this, $status);

    $this->browser->post(reissueUrl($voter))->assertCreated();
})->with(['draft', 'scheduled', 'open']);

it('answers 409 election_codes_locked for a closed, published or archived election [FR-CRED-03] (scenario 6)', function (string $status) {
    [$t, $election, $voter] = reissueSignedIn($this, $status);

    $this->browser->post(reissueUrl($voter))->assertStatus(409)->assertJsonPath('error.code', 'election_codes_locked');
    expect(Accounts::credentialRow($voter))->toBeNull();
})->with(['closed', 'published', 'archived']);

it('answers 404, the same for every case, for a voter that is unknown, deleted, of another institution or not a UUID [FR-INST-05] (scenario 8)', function () {
    [$t, $election, $voter, $group] = reissueSignedIn($this);
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantVoter(['election' => $foreignElection, 'full_name' => 'De B']);
    $gone = Accounts::plantVoter(['election' => $election, 'full_name' => 'Parti']);
    $this->browser->delete("/api/v1/voters/{$gone}")->assertNoContent();

    $unknown = Team::shape($this->browser->post(reissueUrl(REISSUE_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, $gone, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(reissueUrl($target))))->toBe($unknown);
    }
    expect(Accounts::credentialRow($foreign))->toBeNull();
});

it('answers 401, 403, 419 and 405 as every write [NFR-SEC-04] (scenarios 9 to 13)', function () {
    [$t, $election, $voter] = reissueSignedIn($this);

    $this->browser->post(reissueUrl($voter), [], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('GET', reissueUrl($voter))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->post(reissueUrl($voter))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::credentialRow($voter))->toBeNull();
});

it('answers 401 when nobody is signed in [FR-CRED-03] (scenario 9)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Seul']);

    $this->browser->post(reissueUrl($voter))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::credentialRow($voter))->toBeNull();
});

it('lets a manager reissue [FR-CRED-03] (scenario 1)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Géré']);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->post(reissueUrl($voter))->assertCreated();
});

it('writes no code to a log [FR-CRED-02, NFR-SEC-02] (scenario 3)', function () {
    $before = [];
    foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
        $before[$file] = filesize($file);
    }
    [$t, $election, $voter] = reissueSignedIn($this);

    $code = $this->browser->post(reissueUrl($voter))->assertCreated()->json('data.code');

    $logged = '';
    foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
        $logged .= (string) substr((string) file_get_contents($file), $before[$file] ?? 0);
    }
    expect($logged)->not->toContain($code)->and($logged)->not->toContain(str_replace('-', '', $code))->and($logged)->not->toContain('Wideline');
});

it('answers 429 above 60 requests an hour from one user [NFR-SEC-05] (scenario 12)', function () {
    [$t, $election, $voter] = reissueSignedIn($this);

    foreach (range(1, 60) as $i) {
        $this->browser->post(reissueUrl($voter))->assertCreated();
    }

    $this->browser->post(reissueUrl($voter))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
