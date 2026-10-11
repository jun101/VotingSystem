<?php

/*
 * GET /api/v1/elections/{election}/generated-files and GET /api/v1/generated-files/{file} —
 * docs/api/credentials/GET-elections-{election}-generated-files.md, GET-generated-files-{file}.md.
 * Tenant suites: another institution's election or file answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const FILES_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function filesListUrl(string $election): string
{
    return "/api/v1/elections/{$election}/generated-files";
}

function fileUrl(string $file): string
{
    return "/api/v1/generated-files/{$file}";
}

/** An owner of A signed in and an election of the given status. Returns [team, election]. */
function filesSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('lists the files, newest first, then by group name and part [FR-CRED-04] (scenario 1)', function () {
    [$t, $election] = filesSignedIn($this);
    $g4 = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $g3 = Accounts::plantGroup(['election' => $election, 'name' => '3e année']);
    $old = Accounts::plantGeneratedFile(['election' => $election, 'group' => $g4, 'created_at' => '2026-10-10 10:00:00']);
    $b = Accounts::plantGeneratedFile(['election' => $election, 'group' => $g4, 'created_at' => '2026-10-10 11:00:00', 'part' => 2, 'parts' => 2]);
    $a = Accounts::plantGeneratedFile(['election' => $election, 'group' => $g4, 'created_at' => '2026-10-10 11:00:00', 'part' => 1, 'parts' => 2]);
    $c = Accounts::plantGeneratedFile(['election' => $election, 'group' => $g3, 'created_at' => '2026-10-10 11:00:00']);

    $response = $this->browser->get(filesListUrl($election))->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$c, $a, $b, $old])
        ->and($response->json('data.0'))->toMatchArray(['kind' => 'slips_pdf', 'part' => 1, 'parts' => 1, 'status' => 'ready', 'failure' => null])
        ->and($response->json('data.0.group'))->toBe(['id' => $g3, 'name' => '3e année'])
        ->and($response->json('data.0.size_bytes'))->toBeInt()
        ->and($response->json('data.0.id'))->toMatch(UUID_V4);
});

it('answers an empty list for an election with no file [FR-CRED-04] (scenario 2)', function () {
    [$t, $election] = filesSignedIn($this);

    $this->browser->get(filesListUrl($election))->assertOk()->assertExactJson(['data' => []]);
});

it('never lists the files of another election or of another institution [FR-INST-05] (scenario 3)', function () {
    [$t, $election] = filesSignedIn($this);
    $other = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Autre']);
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Accounts::plantGeneratedFile(['election' => $other]);
    Accounts::plantGeneratedFile(['election' => $foreign, 'user' => $t['b']['owner']['user']]);
    $mine = Accounts::plantGeneratedFile(['election' => $election]);

    $this->browser->get(filesListUrl($election))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
});

it('lists no file for a closed, published or archived election [FR-CRED-05] (scenario 4)', function (string $status) {
    [$t, $election] = filesSignedIn($this, $status);
    Accounts::plantGeneratedFile(['election' => $election]);

    $this->browser->get(filesListUrl($election))->assertOk()->assertExactJson(['data' => []]);
})->with(['closed', 'published', 'archived']);

it('keeps the file of a deleted group with a null group [FR-CRED-04] (scenario 1)', function () {
    [$t, $election] = filesSignedIn($this);
    $group = Accounts::plantGroup(['election' => $election, 'name' => 'Disparu']);
    $file = Accounts::plantGeneratedFile(['election' => $election, 'group' => $group]);
    $this->browser->delete("/api/v1/groups/{$group}")->assertNoContent();

    $this->browser->get(filesListUrl($election))->assertOk()->assertJsonPath('data.0.id', $file)->assertJsonPath('data.0.group', null);
});

it('shows one file in each status [FR-CRED-04] (scenarios 1 to 3)', function (string $status, ?string $failure) {
    [$t, $election] = filesSignedIn($this);
    $file = Accounts::plantGeneratedFile(['election' => $election, 'status' => $status, 'voter_count' => 4]);

    $response = $this->browser->get(fileUrl($file))->assertOk();

    expect($response->json('data'))->toMatchArray(['id' => $file, 'kind' => 'slips_pdf', 'group' => null, 'voter_count' => 4, 'status' => $status, 'failure' => $failure])
        ->and($response->json('data.size_bytes') === null)->toBe($status !== 'ready')
        ->and(array_keys($response->json('data')))->toBe(['id', 'kind', 'group', 'part', 'parts', 'voter_count', 'status', 'failure', 'size_bytes', 'expires_at', 'created_at']);
})->with([
    'queued' => ['queued', null],
    'ready' => ['ready', null],
    'failed' => ['failed', 'generation_failed'],
]);

it('answers 404, the same for every case, for a file that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 4)', function () {
    [$t, $election] = filesSignedIn($this);
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantGeneratedFile(['election' => $foreignElection, 'user' => $t['b']['owner']['user']]);

    $unknown = Team::shape($this->browser->get(fileUrl(FILES_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(fileUrl($target))))->toBe($unknown);
    }
});

it('answers 404 for the files list of an election of another institution [FR-INST-05] (scenario 5)', function () {
    [$t, $election] = filesSignedIn($this);
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);

    $unknown = Team::shape($this->browser->get(filesListUrl(FILES_UNKNOWN)));

    expect(Team::shape($this->browser->get(filesListUrl($foreign))))->toBe($unknown);
});

it('answers 401, 403 and 405 as every read [NFR-SEC-04] (scenarios 5 to 7)', function () {
    [$t, $election] = filesSignedIn($this);
    $file = Accounts::plantGeneratedFile(['election' => $election]);

    $this->browser->other('DELETE', fileUrl($file))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $this->browser->other('POST', filesListUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->get(fileUrl($file))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    $this->browser->get(filesListUrl($election))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 401 when nobody is signed in [FR-CRED-04] (scenario 6)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $file = Accounts::plantGeneratedFile(['election' => $election]);

    $this->browser->get(fileUrl($file))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    $this->browser->get(filesListUrl($election))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});
