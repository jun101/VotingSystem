<?php

/*
 * GET /api/v1/voter-imports/{import} — docs/api/voter-imports/GET-voter-imports-{import}.md
 * Tenant suite, route api/v1/voter-imports/{import}: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Spreadsheets;
use Tests\Support\Team;

const IMPORT_SHOW_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function importShowUrl(string $uuid): string
{
    return "/api/v1/voter-imports/{$uuid}";
}

/** An owner of A signed in and an election. Returns [team, election]. */
function importShowSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('gives the summary of a checked import with exactly the documented fields [FR-VOT-03] (scenario 2)', function () {
    [$t, $election] = importShowSignedIn($this);
    $import = Accounts::plantImport(['election' => $election, 'name' => 'eleves-4e-5e-2026.xlsx', 'columns' => ['Nom', 'Classe', 'Matricule', 'Email', 'Téléphone'], 'rows' => [
        ['full_name' => 'Rose', 'group' => '4e année'],
        ['full_name' => 'Jean', 'group' => '5e année'],
        ['full_name' => null, 'status' => 'invalid', 'reasons' => ['full_name_required']],
        ['full_name' => 'Doublon', 'status' => 'duplicate', 'reasons' => ['identifier_exists'], 'identifier' => 'E-1'],
    ]]);

    $response = $this->browser->get(importShowUrl($import))->assertOk();

    $data = $response->json('data');
    expect(array_keys($data))->toEqualCanonicalizing(['id', 'election', 'status', 'original_name', 'columns', 'mapping', 'total_rows',
        'valid_rows', 'invalid_rows', 'duplicate_rows', 'new_groups', 'processed_rows', 'imported_rows', 'failure', 'has_error_file',
        'created_at', 'finished_at'])
        ->and($data)->toMatchArray([
            'id' => $import, 'election' => $election, 'status' => 'checked', 'original_name' => 'eleves-4e-5e-2026.xlsx',
            'columns' => ['Nom', 'Classe', 'Matricule', 'Email', 'Téléphone'],
            'mapping' => ['full_name' => 0, 'group' => 1, 'identifier' => 2, 'email' => 3, 'phone' => 4],
            'total_rows' => 4, 'valid_rows' => 2, 'invalid_rows' => 2, 'duplicate_rows' => 1,
            'new_groups' => ['4e année', '5e année'], 'processed_rows' => 0, 'imported_rows' => 0,
            'failure' => null, 'has_error_file' => true, 'finished_at' => null,
        ]);
});

it('shows an import being checked, running, finished or failed [FR-VOT-04] (scenarios 1, 3, 4, 5)', function () {
    [$t, $election] = importShowSignedIn($this);
    $rows = [['full_name' => 'Rose'], ['full_name' => 'Jean'], ['full_name' => null, 'status' => 'invalid', 'reasons' => ['full_name_required']]];
    $uploaded = Accounts::plantImport(['election' => $election, 'status' => 'uploaded']);
    $running = Accounts::plantImport(['election' => $election, 'status' => 'importing', 'processed' => 2, 'rows' => $rows]);
    $done = Accounts::plantImport(['election' => $election, 'status' => 'done', 'processed' => 3, 'imported' => 2, 'rows' => $rows]);
    $failed = Accounts::plantImport(['election' => $election, 'status' => 'failed', 'failure' => 'too_many_rows']);

    expect($this->browser->get(importShowUrl($uploaded))->assertOk()->json('data'))
        ->toMatchArray(['status' => 'uploaded', 'total_rows' => 0, 'has_error_file' => false]);
    expect($this->browser->get(importShowUrl($running))->assertOk()->json('data'))
        ->toMatchArray(['status' => 'importing', 'processed_rows' => 2, 'total_rows' => 3, 'has_error_file' => false]);
    $final = $this->browser->get(importShowUrl($done))->assertOk()->json('data');
    expect($final)->toMatchArray(['status' => 'done', 'imported_rows' => 2, 'processed_rows' => 3, 'has_error_file' => true])
        ->and($final['finished_at'])->not->toBeNull();
    expect($this->browser->get(importShowUrl($failed))->assertOk()->json('data'))
        ->toMatchArray(['status' => 'failed', 'failure' => 'too_many_rows', 'has_error_file' => false]);
});

it('answers 404, the same for every case, for an import that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 6)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantImport(['election' => $foreignElection, 'rows' => [['full_name' => 'De B']]]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->get(importShowUrl(IMPORT_SHOW_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(importShowUrl($target))))->toBe($unknown);
    }
});

it('answers 401, 403 and 405 [NFR-SEC-04] (scenarios 7, 8 and 10)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $import = Accounts::plantImport(['election' => $election]);

    $this->browser->get(importShowUrl($import))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->other('POST', importShowUrl($import))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->get(importShowUrl($import))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 429 above 240 requests an hour from one user [NFR-SEC-05] (scenario 9)', function () {
    [$t, $election] = importShowSignedIn($this);
    $import = Accounts::plantImport(['election' => $election]);
    foreach (range(1, 240) as $i) {
        $this->browser->get(importShowUrl($import))->assertOk();
    }

    $this->browser->get(importShowUrl($import))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
