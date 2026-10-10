<?php

/*
 * Review round of slice 08: hostile files and the limits that keep the check cheap, the 202 counts, the messages of
 * the new 409 codes, the order of checks of PATCH, what is stored and what is purged.
 * docs/api/voter-imports/ (upload, PATCH, confirm, errors), docs/design/database.md section on voter_imports.
 */

use Illuminate\Support\Facades\Artisan;
use Tests\Support\Accounts;
use Tests\Support\Spreadsheets;
use Tests\Support\Team;

function hardenSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

function hardenUpload($test, string $election, string $bytes, string $name = 'liste.xlsx')
{
    return $test->browser->upload('POST', "/api/v1/elections/{$election}/voter-imports", ['file' => Spreadsheets::upload($bytes, $name)]);
}

function hardenImport($test, string $id): array
{
    return $test->browser->get("/api/v1/voter-imports/{$id}")->assertOk()->json('data');
}

it('answers the upload with integer counts, never null [FR-VOT-03] (scenario 1)', function () {
    [$t, $election] = hardenSignedIn($this);

    $response = hardenUpload($this, $election, Spreadsheets::xlsx([Spreadsheets::HEADER, ['Rose', null, null, null, null]]))->assertStatus(202);

    foreach (['total_rows', 'valid_rows', 'invalid_rows', 'duplicate_rows', 'processed_rows', 'imported_rows'] as $count) {
        expect($response->json("data.{$count}"))->toBeInt();
    }
});

it('gives a message in words for nothing_to_import and no_error_file, in both languages [FR-VOT-03] (scenarios 5, 2)', function (string $language) {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);
    $none = Accounts::plantImport(['election' => $election, 'rows' => [['full_name' => null, 'status' => 'invalid', 'reasons' => ['full_name_required']]]]);
    $clean = Accounts::plantImport(['election' => $election, 'rows' => [['full_name' => 'Rose']]]);
    $headers = ['Accept-Language' => $language];

    $confirm = $this->browser->post("/api/v1/voter-imports/{$none}/confirm", [], $headers)->assertStatus(409);
    $errors = $this->browser->get("/api/v1/voter-imports/{$clean}/errors", $headers)->assertStatus(409);

    expect($confirm->json('error.code'))->toBe('nothing_to_import')
        ->and($confirm->json('error.message'))->not->toStartWith('errors.')->not->toBe('')
        ->and($errors->json('error.code'))->toBe('no_error_file')
        ->and($errors->json('error.message'))->not->toStartWith('errors.')->not->toBe('');
})->with(['fr', 'en']);

it('answers 409 import_not_checked, not 422, for a mapping sent to an import that has no columns yet [FR-VOT-03] (scenario 4)', function (string $status) {
    [$t, $election] = hardenSignedIn($this);
    $import = Accounts::plantImport(['election' => $election, 'status' => $status, 'columns' => null, 'mapping' => null]);

    $this->browser->patch("/api/v1/voter-imports/{$import}", ['mapping' => ['email' => 2]])
        ->assertStatus(409)->assertJsonPath('error.code', 'import_not_checked');
})->with(['uploaded', 'failed']);

it('fails a header of more than 50 columns without storing it [NFR-SEC-06] (scenario 1)', function () {
    [$t, $election] = hardenSignedIn($this);
    $header = array_map(fn ($i) => "colonne_{$i}", range(1, 60));
    $header[0] = 'nom_complet';

    $response = hardenUpload($this, $election, Spreadsheets::csv([$header, ['Rose']]), 'large.csv')->assertStatus(202);

    $import = hardenImport($this, $response->json('data.id'));
    expect($import['status'])->toBe('failed')
        ->and($import['failure'])->toBe('unreadable')
        ->and(count($import['columns'] ?? []))->toBeLessThanOrEqual(50);
});

it('cuts the cells it keeps to the length of their field plus one [NFR-SEC-06] (scenario 1)', function () {
    [$t, $election] = hardenSignedIn($this);

    $response = hardenUpload($this, $election, Spreadsheets::xlsx([Spreadsheets::HEADER, [
        str_repeat('N', 5000), str_repeat('G', 500), str_repeat('I', 500), str_repeat('e', 2000).'@example.ht', str_repeat('1', 400),
    ]]))->assertStatus(202);

    $id = $response->json('data.id');
    $row = Accounts::importRows($id)[0];
    expect(mb_strlen((string) $row['full_name']))->toBeLessThanOrEqual(151)
        ->and(mb_strlen((string) $row['group_name']))->toBeLessThanOrEqual(101)
        ->and(mb_strlen((string) $row['identifier']))->toBeLessThanOrEqual(51)
        ->and(mb_strlen((string) $row['email']))->toBeLessThanOrEqual(256)
        ->and(mb_strlen((string) $row['phone']))->toBeLessThanOrEqual(31)
        ->and(mb_strlen((string) $row['raw']))->toBeLessThan(1200);
    $api = collect($this->browser->get("/api/v1/voter-imports/{$id}/rows")->json('data'))->first();
    expect($api['reasons'])->toContain('full_name_max')
        ->and(mb_strlen((string) $api['full_name']))->toBeLessThanOrEqual(151);
});

it('refuses a zip bomb whose declared size is a lie, fast and without a 500 [NFR-SEC-06] (scenario 4)', function () {
    [$t, $election] = hardenSignedIn($this);
    $start = microtime(true);

    $response = hardenUpload($this, $election, Spreadsheets::bombXlsx(300), 'bombe.xlsx');

    expect($response->getStatusCode())->toBeIn([202, 422]);
    if ($response->getStatusCode() === 422) {
        expect($response->json('error.fields.file'))->toContain('too_large');
    } else {
        $import = hardenImport($this, $response->json('data.id'));
        expect($import['status'])->toBe('failed')
            ->and($import['failure'])->toBeIn(['unreadable', 'too_many_rows']);
    }
    expect(microtime(true) - $start)->toBeLessThan(30.0)
        ->and(Accounts::voterRows($election))->toBe([]);
});

it('does not expand entities of the sheet XML: no file read, no expansion [NFR-SEC-06] (scenario 1)', function () {
    [$t, $election] = hardenSignedIn($this);
    $laughs = '<!DOCTYPE worksheet ['
        .'<!ENTITY a "'.str_repeat('lol', 10).'">'
        .'<!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">'
        .'<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">'
        .'<!ENTITY d "&c;&c;&c;&c;&c;&c;&c;&c;&c;&c;">'
        .'<!ENTITY e "&d;&d;&d;&d;&d;&d;&d;&d;&d;&d;">'
        .'<!ENTITY f "&e;&e;&e;&e;&e;&e;&e;&e;&e;&e;">'
        .'<!ENTITY x SYSTEM "file:///etc/passwd">'
        .']>';
    $rows = '<row r="1"><c r="A1" t="inlineStr"><is><t>nom_complet</t></is></c></row>'
        .'<row r="2"><c r="A2" t="inlineStr"><is><t>&f;</t></is></c></row>'
        .'<row r="3"><c r="A3" t="inlineStr"><is><t>&x;</t></is></c></row>'
        .'<row r="4"><c r="A4" t="inlineStr"><is><t>Rose</t></is></c></row>';
    $start = microtime(true);

    $response = hardenUpload($this, $election, Spreadsheets::xlsxWithSheetXml($rows, $laughs))->assertStatus(202);

    $import = hardenImport($this, $response->json('data.id'));
    expect($import['status'])->toBeIn(['checked', 'failed']);
    if ($import['status'] === 'checked') {
        foreach ($this->browser->get('/api/v1/voter-imports/'.$import['id'].'/rows')->json('data') as $row) {
            expect(mb_strlen((string) $row['full_name']))->toBeLessThan(200)
                ->and((string) $row['full_name'])->not->toContain('root:');
        }
    }
    expect(microtime(true) - $start)->toBeLessThan(30.0);
});

it('stops reading after 11 000 lines, empty ones included, whatever the sheet dimension says [FR-VOT-04] (scenario 1)', function () {
    [$t, $election] = hardenSignedIn($this);
    $rows = '<row r="1"><c r="A1" t="inlineStr"><is><t>nom_complet</t></is></c></row>';
    for ($i = 2; $i <= 12000; $i++) {
        $rows .= '<row r="'.$i.'"><c r="A'.$i.'" t="inlineStr"><is><t></t></is></c></row>';
    }
    $start = microtime(true);

    $response = hardenUpload($this, $election, Spreadsheets::xlsxWithSheetXml($rows, '', '<dimension ref="A1:XFD1048576"/>'))->assertStatus(202);

    $import = hardenImport($this, $response->json('data.id'));
    expect($import['status'])->toBe('failed')
        ->and($import['failure'])->toBe('too_many_rows')
        ->and(microtime(true) - $start)->toBeLessThan(30.0);
});

it('deletes the uploaded file when the import is done, and keeps the error file working [FR-VOT-03] (scenario 2)', function () {
    [$t, $election] = hardenSignedIn($this);
    $response = hardenUpload($this, $election, Spreadsheets::xlsx([Spreadsheets::HEADER, ['Rose', null, null, null, null], [null, '4e', null, null, null]]))->assertStatus(202);
    $id = $response->json('data.id');
    expect(Spreadsheets::importFiles())->toHaveCount(1);

    $this->browser->post("/api/v1/voter-imports/{$id}/confirm")->assertStatus(202);

    expect(hardenImport($this, $id)['status'])->toBe('done')
        ->and(Spreadsheets::importFiles())->toBe([]);
    $this->browser->get("/api/v1/voter-imports/{$id}/errors")->assertOk();
});

it('purges an import stuck in importing for more than a day, and keeps a recent one [FR-VOT-04]', function () {
    [$t, $election] = hardenSignedIn($this);
    $old = now('UTC')->subDays(2)->format('Y-m-d H:i:s');
    $recent = now('UTC')->subHour()->format('Y-m-d H:i:s');
    $stuck = Accounts::plantImport(['election' => $election, 'status' => 'importing', 'updated_at' => $old, 'created_at' => $old, 'rows' => [['full_name' => 'Rose']]]);
    $running = Accounts::plantImport(['election' => $election, 'status' => 'importing', 'updated_at' => $recent, 'created_at' => $recent, 'rows' => [['full_name' => 'Jean']]]);
    $weekOld = Accounts::plantImport(['election' => $election, 'status' => 'checked', 'created_at' => now('UTC')->subDays(8)->format('Y-m-d H:i:s'), 'rows' => [['full_name' => 'Vieux']]]);

    expect(Artisan::call('imports:purge'))->toBe(0);

    expect(Accounts::importRow($stuck))->toBeNull()
        ->and(Accounts::importRows($stuck))->toBe([])
        ->and(Accounts::importRow($weekOld))->toBeNull()
        ->and(Accounts::importRow($running))->not->toBeNull()
        ->and(Accounts::importRows($running))->toHaveCount(1);
});

it('reads a workbook made by a spreadsheet library: inline strings, an accented sheet name, an Instructions sheet after it [FR-VOT-03] (scenario 1)', function () {
    [$t, $election] = hardenSignedIn($this);
    $cell = fn (string $ref, string $text) => '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($text, ENT_XML1).'</t></is></c>';
    $rows = '<row r="1">'.$cell('A1', 'nom_complet').$cell('B1', 'groupe').$cell('C1', 'identifiant').$cell('D1', 'courriel').$cell('E1', 'telephone').'</row>';
    for ($i = 2; $i <= 101; $i++) {
        $rows .= '<row r="'.$i.'">'.$cell("A{$i}", "Électeur {$i}").$cell("B{$i}", '6e année').$cell("C{$i}", "E-{$i}").$cell("D{$i}", "e{$i}@example.ht").$cell("E{$i}", '+509 3728 5146').'</row>';
    }

    $response = hardenUpload($this, $election, Spreadsheets::xlsxWithSheetXml($rows, '', '<dimension ref="A1:E101"/>', 'Électeurs', true), 'modele-electeurs-100.xlsx')->assertStatus(202);

    $import = hardenImport($this, $response->json('data.id'));
    expect($import)->toMatchArray(['status' => 'checked', 'total_rows' => 100, 'valid_rows' => 100, 'invalid_rows' => 0, 'failure' => null])
        ->and($import['new_groups'])->toBe(['6e année']);
});
