<?php

/*
 * DELETE /api/v1/voter-imports/{import} — docs/api/voter-imports/DELETE-voter-imports-{import}.md
 * Tenant suite, route api/v1/voter-imports/{import}: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Spreadsheets;
use Tests\Support\Team;

const IMPORT_DELETE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function importDeleteUrl(string $uuid): string
{
    return "/api/v1/voter-imports/{$uuid}";
}

/** An owner of A signed in and an election. Returns [team, election]. */
function importDeleteSignedIn($test): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('deletes a checked import with its file and its rows [FR-VOT-03] (scenario 1)', function () {
    [$t, $election] = importDeleteSignedIn($this);
    $upload = $this->browser->upload('POST', "/api/v1/elections/{$election}/voter-imports", ['file' => Spreadsheets::upload(Spreadsheets::xlsx([
        Spreadsheets::HEADER, ['Rose', null, null, null, null], ['Jean', null, null, null, null],
    ]))])->assertStatus(202);
    $import = $upload->json('data.id');
    expect(Spreadsheets::importFiles())->toHaveCount(1)
        ->and(Accounts::importRows($import))->toHaveCount(2);

    $this->browser->delete(importDeleteUrl($import))->assertNoContent();

    expect(Accounts::importRow($import))->toBeNull()
        ->and(Accounts::importRows($import))->toBe([])
        ->and(Spreadsheets::importFiles())->toBe([]);
    $this->browser->get(importDeleteUrl($import))->assertStatus(404);
});

it('deletes a failed, done or uploaded import and leaves the voters already written [FR-VOT-03] (scenarios 1, 2)', function (string $status) {
    [$t, $election] = importDeleteSignedIn($this);
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Déjà écrit']);
    $import = Accounts::plantImport(['election' => $election, 'status' => $status, 'rows' => [['full_name' => 'Rose']]]);

    $this->browser->delete(importDeleteUrl($import))->assertNoContent();

    expect(Accounts::importRow($import))->toBeNull()
        ->and(Accounts::voterRow($voter))->not->toBeNull();
})->with(['failed', 'done', 'uploaded']);

it('answers 409 import_in_progress for a running import [FR-VOT-04] (scenario 3)', function () {
    [$t, $election] = importDeleteSignedIn($this);
    $import = Accounts::plantImport(['election' => $election, 'status' => 'importing', 'rows' => [['full_name' => 'Rose']]]);

    $this->browser->delete(importDeleteUrl($import))->assertStatus(409)->assertJsonPath('error.code', 'import_in_progress');

    expect(Accounts::importRow($import))->not->toBeNull()
        ->and(Accounts::importRows($import))->toHaveCount(1);
});

it('answers 404, the same for every case, for an import that is unknown, deleted, of another institution or not a UUID [FR-INST-05] (scenario 4)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantImport(['election' => $foreignElection, 'rows' => [['full_name' => 'De B']]]);
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $gone = Accounts::plantImport(['election' => $election]);
    Team::signIn($this, $t['a']['owner']);
    $this->browser->delete(importDeleteUrl($gone))->assertNoContent();

    $unknown = Team::shape($this->browser->delete(importDeleteUrl(IMPORT_DELETE_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, $gone, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->delete(importDeleteUrl($target))))->toBe($unknown);
    }
    expect(Accounts::importRow($foreign))->not->toBeNull();
});

it('answers 401, 403, 419 and 405 as every write [NFR-SEC-04] (scenarios 5 to 7 and 9)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $import = Accounts::plantImport(['election' => $election]);

    $this->browser->delete(importDeleteUrl($import))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->delete(importDeleteUrl($import), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('PUT', importDeleteUrl($import))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->delete(importDeleteUrl($import))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::importRow($import))->not->toBeNull();
});

it('answers 429 above 240 requests an hour from one user [NFR-SEC-05] (scenario 8)', function () {
    [$t, $election] = importDeleteSignedIn($this);
    $ids = [];
    foreach (range(1, 241) as $i) {
        $ids[] = Accounts::plantImport(['election' => $election, 'status' => 'failed']);
    }

    foreach (array_slice($ids, 0, 240) as $id) {
        $this->browser->delete(importDeleteUrl($id))->assertNoContent();
    }

    $this->browser->delete(importDeleteUrl($ids[240]))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect(Accounts::importRow($ids[240]))->not->toBeNull();
});

it('is deleted with the election, files and rows included [FR-ELEC-03] (scenario 1)', function () {
    [$t, $election] = importDeleteSignedIn($this);
    $upload = $this->browser->upload('POST', "/api/v1/elections/{$election}/voter-imports", ['file' => Spreadsheets::upload(Spreadsheets::xlsx([
        Spreadsheets::HEADER, ['Rose', null, null, null, null],
    ]))])->assertStatus(202);
    $import = $upload->json('data.id');

    $this->browser->delete("/api/v1/elections/{$election}")->assertNoContent();

    expect(Accounts::importRow($import))->toBeNull()
        ->and(Accounts::importRows($import))->toBe([])
        ->and(Spreadsheets::importFiles())->toBe([]);
});
