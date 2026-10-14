<?php

/*
 * POST /api/v1/voter-imports/{import}/confirm — docs/api/voter-imports/POST-voter-imports-{import}-confirm.md
 * Also the import job it queues (the queue runs `sync` in the tests).
 * Tenant suite, route api/v1/voter-imports/{import}/confirm: another institution's record answers 404, the same as an
 * unknown one.
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\Spreadsheets;
use Tests\Support\Team;

const IMPORT_CONFIRM_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function importConfirmUrl(string $uuid): string
{
    return "/api/v1/voter-imports/{$uuid}/confirm";
}

/** The rows of a small checked file: four valid, one without a name, one duplicate. */
function importConfirmRows(): array
{
    return [
        ['full_name' => 'Rose-Marie Désir', 'group' => '4e année', 'identifier' => 'E-1', 'email' => 'rm.desir@example.ht', 'phone' => '+509 3712 4455'],
        ['full_name' => 'Jean-Baptiste Pierre', 'group' => '4E ANNÉE', 'identifier' => 'E-2'],
        ['full_name' => 'Kervens Étienne', 'group' => 'terminale a', 'identifier' => 'E-3'],
        ['full_name' => 'Sans Groupe'],
        ['full_name' => null, 'status' => 'invalid', 'reasons' => ['full_name_required'], 'group' => '5e année'],
        ['full_name' => 'Doublon', 'status' => 'duplicate', 'reasons' => ['identifier_exists'], 'identifier' => 'E-9'],
    ];
}

/** An owner of A signed in, an election and a checked import of the rows. Returns [team, election, import]. */
function importConfirmSignedIn($test, string $status = 'draft', ?array $rows = null): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $import = Accounts::plantImport(['election' => $election, 'rows' => $rows ?? importConfirmRows()]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $import];
}

it('writes the valid rows and the groups they need, and nothing else [FR-VOT-03] (scenarios 1, 2)', function (string $status) {
    [$t, $election, $import] = importConfirmSignedIn($this, $status);
    $existing = Accounts::plantGroup(['election' => $election, 'name' => 'Terminale A']);

    $response = $this->browser->post(importConfirmUrl($import))->assertStatus(202);

    expect($response->json('data.status'))->toBeIn(['importing', 'done']);
    $done = $this->browser->get("/api/v1/voter-imports/{$import}")->assertOk()->json('data');
    expect($done)->toMatchArray(['status' => 'done', 'imported_rows' => 4, 'processed_rows' => 6, 'valid_rows' => 4, 'invalid_rows' => 2, 'failure' => null])
        ->and($done['finished_at'])->not->toBeNull();

    $voters = collect(Accounts::voterRows($election))->keyBy('full_name');
    expect($voters->keys()->sort()->values()->all())->toBe(['Jean-Baptiste Pierre', 'Kervens Étienne', 'Rose-Marie Désir', 'Sans Groupe'])
        ->and($voters['Rose-Marie Désir'])->toMatchArray(['identifier' => 'E-1', 'email' => 'rm.desir@example.ht', 'phone' => '+509 3712 4455'])
        ->and($voters['Sans Groupe']['voter_group_id'])->toBeNull();

    $groups = collect(Accounts::groupRows($election))->keyBy('name');
    expect($groups->keys()->sort()->values()->all())->toBe(['4e année', 'Terminale A'])
        ->and($voters['Rose-Marie Désir']['voter_group_id'])->toBe($groups['4e année']['id'])
        ->and($voters['Jean-Baptiste Pierre']['voter_group_id'])->toBe($groups['4e année']['id'])
        ->and($groups['Terminale A']['uuid'])->toBe($existing)
        ->and($voters['Kervens Étienne']['voter_group_id'])->toBe($groups['Terminale A']['id']);
    // The institution and the election are the import's own.
    expect($voters['Sans Groupe']['institution_id'])->toBe(Accounts::electionRow($election)['institution_id']);
})->with(['draft', 'scheduled']);

it('re-checks the duplicates at import time and puts the late ones in the error file [FR-VOT-03] (scenario 3)', function () {
    [$t, $election, $import] = importConfirmSignedIn($this);
    // Added by hand after the check ran.
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Arrivé entre-temps', 'identifier' => 'E-1']);

    $this->browser->post(importConfirmUrl($import))->assertStatus(202);

    $done = $this->browser->get("/api/v1/voter-imports/{$import}")->json('data');
    expect($done)->toMatchArray(['status' => 'done', 'imported_rows' => 3, 'valid_rows' => 3, 'invalid_rows' => 3, 'has_error_file' => true])
        ->and(collect(Accounts::voterRows($election))->pluck('full_name')->all())->not->toContain('Rose-Marie Désir');
    $file = $this->browser->get("/api/v1/voter-imports/{$import}/errors")->assertOk();
    $names = array_column(array_slice(Spreadsheets::read(Spreadsheets::body($file)), 1), 0);
    expect($names)->toContain('Rose-Marie Désir');
});

it('imports 1 200 rows in chunks [FR-VOT-04] (scenario 2)', function () {
    $rows = [];
    for ($i = 1; $i <= 1200; $i++) {
        $rows[] = ['full_name' => "Électeur {$i}", 'group' => 'Groupe '.($i % 7), 'identifier' => "ID-{$i}", 'email' => "e{$i}@example.ht"];
    }
    [$t, $election, $import] = importConfirmSignedIn($this, 'draft', $rows);

    $this->browser->post(importConfirmUrl($import))->assertStatus(202);

    $done = $this->browser->get("/api/v1/voter-imports/{$import}")->json('data');
    expect($done)->toMatchArray(['status' => 'done', 'imported_rows' => 1200, 'processed_rows' => 1200])
        ->and(Accounts::voterRows($election))->toHaveCount(1200)
        ->and(Accounts::groupRows($election))->toHaveCount(7);
});

it('answers 409 import_not_checked for an import that is not checked, and for a second confirmation [FR-VOT-03] (scenario 4)', function () {
    [$t, $election, $import] = importConfirmSignedIn($this);

    $this->browser->post(importConfirmUrl($import))->assertStatus(202);
    $this->browser->post(importConfirmUrl($import))->assertStatus(409)->assertJsonPath('error.code', 'import_not_checked');
    expect(Accounts::voterRows($election))->toHaveCount(4);

    foreach (['uploaded', 'importing', 'failed'] as $status) {
        $other = Accounts::plantImport(['election' => $election, 'status' => $status, 'rows' => [['full_name' => 'Pas écrit']]]);
        $this->browser->post(importConfirmUrl($other))->assertStatus(409)->assertJsonPath('error.code', 'import_not_checked');
    }
    expect(Accounts::voterRows($election))->toHaveCount(4);
});

it('answers 409 nothing_to_import when no row is valid [FR-VOT-03] (scenario 5)', function () {
    [$t, $election, $import] = importConfirmSignedIn($this, 'draft', [
        ['full_name' => null, 'status' => 'invalid', 'reasons' => ['full_name_required']],
    ]);

    $this->browser->post(importConfirmUrl($import))->assertStatus(409)->assertJsonPath('error.code', 'nothing_to_import');

    expect(Accounts::importRow($import)['status'])->toBe('checked');
});

it('answers 409 voter_limit_reached when the election would pass 10 000 voters [FR-VOT-04] (scenario 6)', function () {
    [$t, $election] = importConfirmSignedIn($this);
    $row = Accounts::electionRow($election);
    $now = date('Y-m-d H:i:s');
    foreach (array_chunk(range(1, 9998), 1000) as $chunk) {
        DB::connection(useMigratorConnection())->table('voters')->insert(array_map(fn ($n) => [
            'uuid' => (string) Illuminate\Support\Str::uuid(), 'institution_id' => $row['institution_id'], 'election_id' => $row['id'],
            'full_name' => "Existant {$n}", 'created_at' => $now, 'updated_at' => $now,
        ], $chunk));
    }
    $three = Accounts::plantImport(['election' => $election, 'rows' => [['full_name' => 'A'], ['full_name' => 'B'], ['full_name' => 'C']]]);
    $two = Accounts::plantImport(['election' => $election, 'rows' => [['full_name' => 'D'], ['full_name' => 'E']]]);

    $this->browser->post(importConfirmUrl($three))->assertStatus(409)->assertJsonPath('error.code', 'voter_limit_reached');
    expect(Accounts::importRow($three)['status'])->toBe('checked')
        ->and(Accounts::voterRows($election))->toHaveCount(9998);

    $this->browser->post(importConfirmUrl($two))->assertStatus(202);
    expect(Accounts::voterRows($election))->toHaveCount(10000);
});

it('answers 409 election_voters_locked before the import state once the election is not a draft or scheduled [FR-SEC-06] (scenario 7)', function (string $status) {
    [$t, $election, $import] = importConfirmSignedIn($this, $status);

    $this->browser->post(importConfirmUrl($import))->assertStatus(409)->assertJsonPath('error.code', 'election_voters_locked');

    expect(Accounts::voterRows($election))->toBe([])
        ->and(Accounts::importRow($import)['status'])->toBe('checked');
})->with(['open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for an import that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 8)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantImport(['election' => $foreignElection, 'rows' => [['full_name' => 'De B']]]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->post(importConfirmUrl(IMPORT_CONFIRM_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(importConfirmUrl($target))))->toBe($unknown);
    }
    expect(Accounts::voterRows($foreignElection))->toBe([]);
});

it('answers 401, 403, 419 and 405 as every write [NFR-SEC-04] (scenarios 9 to 11 and 13)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $import = Accounts::plantImport(['election' => $election, 'rows' => [['full_name' => 'Rose']]]);

    $this->browser->post(importConfirmUrl($import))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->post(importConfirmUrl($import), [], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('GET', importConfirmUrl($import))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->post(importConfirmUrl($import))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::voterRows($election))->toBe([]);
});

it('answers 429 above 240 requests an hour from one user [NFR-SEC-05] (scenario 12)', function () {
    [$t, $election, $import] = importConfirmSignedIn($this, 'draft', [['full_name' => 'Rose']]);
    $this->browser->post(importConfirmUrl($import))->assertStatus(202);
    foreach (range(2, 240) as $i) {
        $this->browser->post(importConfirmUrl($import))->assertStatus(409);
    }

    $this->browser->post(importConfirmUrl($import))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
