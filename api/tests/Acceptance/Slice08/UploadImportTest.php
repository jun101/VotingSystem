<?php

/*
 * POST /api/v1/elections/{election}/voter-imports — docs/api/voter-imports/POST-elections-{election}-voter-imports.md
 * Also the check job it queues (the queue runs `sync` in the tests): the rows, the counts, the mapping proposed.
 * Tenant suite, route api/v1/elections/{election}/voter-imports: another institution's election answers 404, the same
 * as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Spreadsheets;
use Tests\Support\Team;

const IMPORT_UPLOAD_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function importUploadUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/voter-imports";
}

/** An owner of A signed in and an election of the given status. Returns [team, election]. */
function importUploadSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

/** Uploads bytes, returns the response. */
function importUpload($test, string $election, string $bytes, string $name = 'electeurs.xlsx')
{
    return $test->browser->upload('POST', importUploadUrl($election), ['file' => Spreadsheets::upload($bytes, $name)]);
}

/** Uploads an accepted file and returns the import resource read again (the check has run). */
function importUploaded($test, string $election, string $bytes, string $name = 'electeurs.xlsx'): array
{
    $response = importUpload($test, $election, $bytes, $name)->assertStatus(202);

    return $test->browser->get('/api/v1/voter-imports/'.$response->json('data.id'))->assertOk()->json('data');
}

function importUploadRows($test, string $import, string $query = ''): array
{
    return $test->browser->get("/api/v1/voter-imports/{$import}/rows{$query}")->assertOk()->json('data');
}

it('accepts an xlsx, stores it under a random name and checks it [FR-VOT-03] (scenario 1)', function () {
    [$t, $election] = importUploadSignedIn($this);

    $response = importUpload($this, $election, Spreadsheets::xlsx([
        Spreadsheets::HEADER,
        ['Rose-Marie Désir', '4e année', 'E-2041', 'rm.desir@example.ht', '+509 3712 4455'],
        ['Jean-Baptiste Pierre', '4e année', 'E-2042', null, null],
    ]), 'eleves-4e-2026.xlsx')->assertStatus(202);

    $id = $response->json('data.id');
    expect($response->json('data.status'))->toBeIn(['uploaded', 'checked'])
        ->and($response->headers->get('Location'))->toBe("/api/v1/voter-imports/{$id}")
        ->and($response->json('data.original_name'))->toBe('eleves-4e-2026.xlsx');

    $import = $this->browser->get("/api/v1/voter-imports/{$id}")->assertOk()->json('data');
    expect($import)->toMatchArray([
        'status' => 'checked', 'election' => $election, 'original_name' => 'eleves-4e-2026.xlsx',
        'total_rows' => 2, 'valid_rows' => 2, 'invalid_rows' => 0, 'duplicate_rows' => 0,
        'new_groups' => ['4e année'], 'failure' => null, 'has_error_file' => false,
        'mapping' => ['full_name' => 0, 'group' => 1, 'identifier' => 2, 'email' => 3, 'phone' => 4],
    ]);
    expect($import['columns'])->toBe(Spreadsheets::HEADER);

    $files = Spreadsheets::importFiles();
    expect($files)->toHaveCount(1)
        ->and($files[0])->not->toContain('eleves')
        ->and(Accounts::importRow($id)['institution_id'])->toBe(Accounts::electionRow($election)['institution_id']);
    // Nothing is written to the election before the confirmation.
    expect(Accounts::voterRows($election))->toBe([])
        ->and(Accounts::groupRows($election))->toBe([]);
});

it('accepts a csv, with a comma or a semicolon, and proposes the mapping by header name [FR-VOT-03] (scenario 2)', function (string $separator) {
    [$t, $election] = importUploadSignedIn($this);

    $import = importUploaded($this, $election, Spreadsheets::csv([
        ['Email', 'Name', 'Classe', 'Matricule', 'Téléphone'],
        ['a@example.ht', 'Nadège Louis', '5e année', 'E-2107', '+509 1111 1111'],
    ], $separator), 'liste.csv');

    expect($import['mapping'])->toBe(['full_name' => 1, 'group' => 2, 'identifier' => 3, 'email' => 0, 'phone' => 4])
        ->and($import['columns'])->toBe(['Email', 'Name', 'Classe', 'Matricule', 'Téléphone'])
        ->and($import['valid_rows'])->toBe(1);
    $rows = importUploadRows($this, $import['id']);
    expect($rows[0])->toMatchArray(['line' => 2, 'status' => 'ok', 'full_name' => 'Nadège Louis', 'group' => '5e année',
        'identifier' => 'E-2107', 'email' => 'a@example.ht', 'phone' => '+509 1111 1111']);
})->with(['comma' => [','], 'semicolon' => [';']]);

it('reads a csv with a byte-order mark or in Windows-1252 [FR-VOT-03] (scenario 2)', function (bool $bom, string $encoding) {
    [$t, $election] = importUploadSignedIn($this);

    $import = importUploaded($this, $election, Spreadsheets::csv([
        ['nom_complet', 'groupe'],
        ['Édouard Joël', 'Terminale É'],
    ], ';', $bom, $encoding), 'liste.csv');

    $rows = importUploadRows($this, $import['id']);
    expect($rows[0]['full_name'])->toBe('Édouard Joël')
        ->and($rows[0]['group'])->toBe('Terminale É');
})->with([
    'utf-8 with a mark' => [true, 'UTF-8'],
    'utf-8 without' => [false, 'UTF-8'],
    'windows-1252' => [false, 'Windows-1252'],
]);

it('judges the file by its content, not by its name [NFR-SEC-06] (scenario 1)', function () {
    [$t, $election] = importUploadSignedIn($this);

    $import = importUploaded($this, $election, Spreadsheets::xlsx([Spreadsheets::HEADER, ['Rose', null, null, null, null]]), 'liste.txt');

    expect($import['status'])->toBe('checked')
        ->and($import['valid_rows'])->toBe(1);
});

it('answers 422 file required when there is no file [FR-VOT-03] (scenario 3)', function () {
    [$t, $election] = importUploadSignedIn($this);

    $response = $this->browser->upload('POST', importUploadUrl($election), [])->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')
        ->and($response->json('error.fields.file'))->toContain('required')
        ->and(Spreadsheets::importFiles())->toBe([]);
});

it('answers 422 invalid_type for a file whose content is not a spreadsheet or text [NFR-SEC-06] (scenario 4)', function (string $name, string $bytes) {
    [$t, $election] = importUploadSignedIn($this);

    $response = importUpload($this, $election, $bytes, $name)->assertStatus(422);

    expect($response->json('error.fields.file'))->toContain('invalid_type')
        ->and(Spreadsheets::importFiles())->toBe([])
        ->and(Accounts::importsOf($election))->toBe([]);
})->with([
    'a png named xlsx' => ['liste.xlsx', Tests\Support\Images::png(20, 20)],
    'a zip that is not a workbook' => ['liste.xlsx', Spreadsheets::zip(['readme.txt' => 'bonjour'])],
    'binary named csv' => ['liste.csv', "nom\x00\x01\x02\xff\xfe,groupe\x00"],
    'a pdf named csv' => ['liste.csv', "%PDF-1.7\n%\xe2\xe3\xcf\xd3\n1 0 obj\x00<<>>"],
]);

it('answers 422 empty for an empty file [FR-VOT-03] (scenario 5)', function () {
    [$t, $election] = importUploadSignedIn($this);

    $response = importUpload($this, $election, '', 'vide.csv')->assertStatus(422);

    expect($response->json('error.fields.file'))->toContain('empty');
});

it('answers 422 too_large above 5 MB [NFR-SEC-06] (scenario 6)', function () {
    [$t, $election] = importUploadSignedIn($this);
    $bytes = "nom_complet\n".str_repeat("Rose-Marie Désir\n", (int) ceil(5242880 / 17));

    $response = importUpload($this, $election, $bytes, 'gros.csv')->assertStatus(422);

    expect($response->json('error.fields.file'))->toContain('too_large')
        ->and(Spreadsheets::importFiles())->toBe([]);
});

it('answers 409 import_in_progress while an import is uploaded or importing [FR-VOT-04] (scenario 7)', function (string $status) {
    [$t, $election] = importUploadSignedIn($this);
    $running = Accounts::plantImport(['election' => $election, 'status' => $status]);

    $response = importUpload($this, $election, Spreadsheets::xlsx([Spreadsheets::HEADER, ['Rose', null, null, null, null]]))
        ->assertStatus(409)->assertJsonPath('error.code', 'import_in_progress');

    expect(Accounts::importsOf($election))->toBe([$running]);
})->with(['uploaded', 'importing']);

it('replaces the earlier checked and failed imports of the election [FR-VOT-03] (scenario 1)', function () {
    [$t, $election] = importUploadSignedIn($this);
    $other = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Autre']);
    $checked = Accounts::plantImport(['election' => $election, 'status' => 'checked', 'rows' => [['full_name' => 'Ancien']]]);
    $failed = Accounts::plantImport(['election' => $election, 'status' => 'failed', 'failure' => 'unreadable']);
    $elsewhere = Accounts::plantImport(['election' => $other, 'status' => 'checked', 'rows' => [['full_name' => 'Ailleurs']]]);

    $import = importUploaded($this, $election, Spreadsheets::xlsx([Spreadsheets::HEADER, ['Rose', null, null, null, null]]));

    expect(Accounts::importsOf($election))->toBe([$import['id']])
        ->and(Accounts::importRow($checked))->toBeNull()
        ->and(Accounts::importRow($failed))->toBeNull()
        ->and(Accounts::importRows($elsewhere))->toHaveCount(1);
});

it('answers 409 election_voters_locked for an open, closed, published or archived election [FR-SEC-06] (scenario 8)', function (string $status) {
    [$t, $election] = importUploadSignedIn($this, $status);

    importUpload($this, $election, Spreadsheets::xlsx([Spreadsheets::HEADER]))
        ->assertStatus(409)->assertJsonPath('error.code', 'election_voters_locked');

    expect(Spreadsheets::importFiles())->toBe([])
        ->and(Accounts::importsOf($election))->toBe([]);
})->with(['open', 'closed', 'published', 'archived']);

it('accepts a draft or a scheduled election [FR-VOT-03] (scenario 1)', function (string $status) {
    [$t, $election] = importUploadSignedIn($this, $status);

    importUpload($this, $election, Spreadsheets::xlsx([Spreadsheets::HEADER, ['Rose', null, null, null, null]]))->assertStatus(202);
})->with(['draft', 'scheduled']);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 9)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);
    $bytes = Spreadsheets::xlsx([Spreadsheets::HEADER]);

    $unknown = Team::shape(importUpload($this, IMPORT_UPLOAD_UNKNOWN, $bytes));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape(importUpload($this, $target, $bytes)))->toBe($unknown);
    }
    expect(Accounts::importsOf($foreign))->toBe([]);
});

it('answers 401, 403, 419 and 405 as every write [NFR-SEC-04] (scenarios 10 to 14)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $file = ['file' => Spreadsheets::upload(Spreadsheets::xlsx([Spreadsheets::HEADER]))];

    $this->browser->upload('POST', importUploadUrl($election), $file)->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->upload('POST', importUploadUrl($election), $file, [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('PUT', importUploadUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->upload('POST', importUploadUrl($election), $file)->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::importsOf($election))->toBe([]);
});

it('answers 429 above 20 uploads an hour from one user [NFR-SEC-05] (scenario 13)', function () {
    [$t, $election] = importUploadSignedIn($this);
    $bytes = Spreadsheets::csv([['nom_complet'], ['Rose']]);

    foreach (range(1, 20) as $i) {
        importUpload($this, $election, $bytes, 'liste.csv')->assertStatus(202);
    }

    importUpload($this, $election, $bytes, 'liste.csv')->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});

it('ends in status failed with a code for a file that cannot be used [FR-VOT-04] (scenario 1)', function (string $bytes, string $name, string $failure) {
    [$t, $election] = importUploadSignedIn($this);

    $import = importUploaded($this, $election, $bytes, $name);

    expect($import['status'])->toBe('failed')
        ->and($import['failure'])->toBe($failure)
        ->and($import['has_error_file'])->toBeFalse()
        ->and(Accounts::voterRows($election))->toBe([]);
})->with([
    'no header at all' => [Spreadsheets::csv([['', ''], ['', '']]), 'vide.csv', 'no_header'],
    'no full name column' => [Spreadsheets::xlsx([['groupe', 'courriel'], ['4e', 'a@example.ht']]), 'liste.xlsx', 'no_full_name_column'],
    'more than 10 000 rows' => [Spreadsheets::manyRows(10001), 'immense.xlsx', 'too_many_rows'],
]);

it('takes 10 000 rows [FR-VOT-04] (scenario 1)', function () {
    [$t, $election] = importUploadSignedIn($this);

    $import = importUploaded($this, $election, Spreadsheets::manyRows(10000));

    expect($import['status'])->toBe('checked')
        ->and($import['total_rows'])->toBe(10000)
        ->and($import['valid_rows'])->toBe(10000)
        ->and($import['new_groups'])->toHaveCount(5);
});

it('checks every row: missing name, bad email, bad phone, lengths, duplicates in the file and in the election [FR-VOT-03] (scenario 1)', function () {
    [$t, $election] = importUploadSignedIn($this);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Déjà là', 'identifier' => 'E-9001', 'email' => 'deja@example.ht']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Aussi là', 'identifier' => 'E-9002', 'email' => 'aussi@example.ht']);

    $import = importUploaded($this, $election, Spreadsheets::xlsx([
        Spreadsheets::HEADER,
        /* 2 */ ['Rose-Marie Désir', '4e année', 'E-2041', 'rm.desir@example.ht', '+509 3712 4455'],
        /* 3 */ [null, '5e année', 'E-2107', null, null],
        /* 4 */ ['Nadège Louis', '5e année', 'E-2108', 'nadege.louis@', null],
        /* 5 */ ['Wideline Joseph', '4E ANNÉE', ' e-2041 ', null, null],
        /* 6 */ ['Kervens Étienne', 'Terminale A', 'E-3010', 'RM.DESIR@example.ht', null],
        /* 7 */ ['Mirlande Jean-Louis', 'Terminale A', 'E-9001', null, null],
        /* 8 */ ['Pierre Charles', null, 'E-3011', 'AUSSI@example.ht', null],
        /* 9 */ ['Sandra Delva', null, 'E-3012', null, '37124455abc'],
        /* 10 */ [str_repeat('x', 151), null, 'E-3013', null, null],
        /* 11 */ ['Yves Toussaint', null, str_repeat('9', 51), null, null],
        /* 12 */ ['Same Name', '4e année', 'E-3014', null, null],
        /* 13 */ ['Same Name', '4e année', 'E-3015', null, null],
    ]));

    expect($import)->toMatchArray(['total_rows' => 12, 'valid_rows' => 3, 'invalid_rows' => 9, 'duplicate_rows' => 4, 'has_error_file' => true])
        ->and($import['new_groups'])->toBe(['4e année']);
    $by = collect(importUploadRows($this, $import['id'], '?filter=all'))->keyBy('line');
    expect($by[2]['status'])->toBe('ok')
        ->and($by[3])->toMatchArray(['status' => 'invalid', 'reasons' => ['full_name_required']])
        ->and($by[4])->toMatchArray(['status' => 'invalid', 'reasons' => ['email_invalid']])
        ->and($by[5])->toMatchArray(['status' => 'duplicate', 'reasons' => ['duplicate_identifier_in_file'], 'identifier' => 'e-2041'])
        ->and($by[6])->toMatchArray(['status' => 'duplicate', 'reasons' => ['duplicate_email_in_file'], 'email' => 'rm.desir@example.ht'])
        ->and($by[7])->toMatchArray(['status' => 'duplicate', 'reasons' => ['identifier_exists']])
        ->and($by[8])->toMatchArray(['status' => 'duplicate', 'reasons' => ['email_exists']])
        ->and($by[9])->toMatchArray(['status' => 'invalid', 'reasons' => ['phone_invalid']])
        ->and($by[10])->toMatchArray(['status' => 'invalid', 'reasons' => ['full_name_max']])
        ->and($by[11])->toMatchArray(['status' => 'invalid', 'reasons' => ['identifier_max']])
        ->and($by[12]['status'])->toBe('ok')
        ->and($by[13]['status'])->toBe('ok');
    // Nothing is written yet.
    expect(Accounts::voterRows($election))->toHaveCount(2);
});

it('takes the same group name in another case as one group, and counts the new ones in file order [FR-VOT-03] (scenario 1)', function () {
    [$t, $election] = importUploadSignedIn($this);
    Accounts::plantGroup(['election' => $election, 'name' => 'Terminale A']);

    $import = importUploaded($this, $election, Spreadsheets::xlsx([
        Spreadsheets::HEADER,
        ['A', '4e année', null, null, null],
        ['B', '  4E ANNÉE ', null, null, null],
        ['C', '5e année', null, null, null],
        ['D', 'terminale a', null, null, null],
    ]));

    expect($import['new_groups'])->toBe(['4e année', '5e année'])
        ->and($import['valid_rows'])->toBe(4);
});

it('makes a row invalid when it needs a 101st group [FR-VOT-08] (scenario 1)', function () {
    [$t, $election] = importUploadSignedIn($this);
    foreach (range(1, 100) as $i) {
        Accounts::plantGroup(['election' => $election, 'name' => "Groupe {$i}"]);
    }

    $import = importUploaded($this, $election, Spreadsheets::xlsx([
        Spreadsheets::HEADER,
        ['Dans un groupe', 'Groupe 7', null, null, null],
        ['Trop de groupes', 'Un nouveau', null, null, null],
    ]));

    expect($import)->toMatchArray(['valid_rows' => 1, 'invalid_rows' => 1]);
    $by = collect(importUploadRows($this, $import['id']))->keyBy('line');
    expect($by[3]['reasons'])->toBe(['group_limit_reached']);
});

it('skips empty rows without counting them and keeps the line numbers [FR-VOT-03] (scenario 1)', function () {
    [$t, $election] = importUploadSignedIn($this);

    $import = importUploaded($this, $election, Spreadsheets::xlsx([
        Spreadsheets::HEADER,
        ['Rose', null, null, null, null],
        [null, null, null, null, null],
        ['  ', '', null, null, null],
        ['Jean', null, null, null, null],
    ]));

    expect($import['total_rows'])->toBe(2);
    $rows = importUploadRows($this, $import['id']);
    expect(array_column($rows, 'line'))->toBe([2, 5]);
});

it('never evaluates a formula and keeps text that looks like one as text [NFR-SEC-06] (scenario 1)', function () {
    [$t, $election] = importUploadSignedIn($this);

    $import = importUploaded($this, $election, Spreadsheets::xlsx([
        Spreadsheets::HEADER,
        [Spreadsheets::formula('HYPERLINK("http://evil.example/x","Cliquez ici")', 'Cliquez ici'), '4e année', 'E-1', null, null],
        ['=cmd|\' /C calc\'!A1', null, 'E-2', null, null],
        ['+1+1', null, 'E-3', null, null],
    ]));

    $rows = importUploadRows($this, $import['id']);
    expect($rows[0]['full_name'])->toBe('Cliquez ici')
        ->and($rows[1]['full_name'])->toBe('=cmd|\' /C calc\'!A1')
        ->and($rows[2]['full_name'])->toBe('+1+1')
        ->and($import['valid_rows'])->toBe(3);
});

it('writes no cell of the file in the logs [NFR-SEC-06] (scenario 1)', function () {
    [$t, $election] = importUploadSignedIn($this);

    $import = importUploaded($this, $election, Spreadsheets::xlsx([Spreadsheets::HEADER, ['Rose-Marie Désir', null, 'E-SECRET-1', 'secret@example.ht', null]]));

    $log = '';
    foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
        $log .= (string) file_get_contents($file);
    }
    expect($import['valid_rows'])->toBe(1)
        ->and($log)->not->toContain('E-SECRET-1')->not->toContain('secret@example.ht')->not->toContain('Rose-Marie Désir');
});
