<?php

/*
 * GET /api/v1/voter-imports/{import}/errors — docs/api/voter-imports/GET-voter-imports-{import}-errors.md
 * Tenant suite, route api/v1/voter-imports/{import}/errors: another institution's record answers 404, the same as an
 * unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Spreadsheets;
use Tests\Support\Team;

const IMPORT_ERRORS_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function importErrorsUrl(string $uuid): string
{
    return "/api/v1/voter-imports/{$uuid}/errors";
}

/** An owner of A signed in and an election. Returns [team, election]. */
function importErrorsSignedIn($test): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('gives the rejected rows as an xlsx with the template columns, the line and the reason [FR-VOT-03] (scenario 1)', function (string $status) {
    [$t, $election] = importErrorsSignedIn($this);
    $import = Accounts::plantImport(['election' => $election, 'status' => $status, 'rows' => [
        ['full_name' => 'Rose', 'group' => '4e année'],
        ['line' => 4, 'status' => 'invalid', 'reasons' => ['full_name_required'], 'full_name' => null, 'group' => '5e année', 'identifier' => 'E-2107'],
        ['line' => 5, 'status' => 'invalid', 'reasons' => ['email_invalid'], 'full_name' => 'Nadège Louis', 'group' => '5e année', 'identifier' => 'E-2108', 'email' => 'nadege.louis@'],
        ['line' => 6, 'status' => 'duplicate', 'reasons' => ['duplicate_identifier_in_file'], 'full_name' => 'Wideline Joseph', 'identifier' => 'E-2041'],
    ]]);

    $response = $this->browser->get(importErrorsUrl($import))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('erreurs-electeurs.xlsx')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    $rows = Spreadsheets::read(Spreadsheets::body($response));
    expect($rows[0])->toBe([...Spreadsheets::HEADER, 'ligne', 'raison'])
        ->and(count($rows))->toBe(4)
        ->and(array_slice($rows[1], 0, 7)[5])->toBe('4')
        ->and($rows[2])->toMatchArray([0 => 'Nadège Louis', 1 => '5e année', 2 => 'E-2108', 3 => 'nadege.louis@', 5 => '5'])
        ->and($rows[3][5])->toBe('6');
    // The reason is in words, not the code.
    foreach (array_slice($rows, 1) as $row) {
        expect($row[6])->not->toBe('')
            ->and($row[6])->not->toContain('_');
    }
})->with(['checked', 'done']);

it('protects every cell that could run as a formula [NFR-SEC-06] (scenario 1)', function () {
    [$t, $election] = importErrorsSignedIn($this);
    $import = Accounts::plantImport(['election' => $election, 'rows' => [
        ['status' => 'invalid', 'reasons' => ['email_invalid'], 'full_name' => '=HYPERLINK("http://evil.example","x")', 'group' => '+cmd', 'identifier' => '-1', 'email' => '@x', 'phone' => '=1+1'],
    ]]);

    $rows = Spreadsheets::read(Spreadsheets::body($this->browser->get(importErrorsUrl($import))->assertOk()));

    expect($rows[1][0])->toBe("'=HYPERLINK(\"http://evil.example\",\"x\")")
        ->and($rows[1][1])->toBe("'+cmd")
        ->and($rows[1][2])->toBe("'-1")
        ->and($rows[1][3])->toBe("'@x")
        ->and($rows[1][4])->toBe("'=1+1");
});

it('answers 409 no_error_file when there is no rejected row or the import is not checked or done [FR-VOT-03] (scenario 2)', function () {
    [$t, $election] = importErrorsSignedIn($this);
    $bad = [['status' => 'invalid', 'reasons' => ['full_name_required'], 'full_name' => null]];

    $clean = Accounts::plantImport(['election' => $election, 'rows' => [['full_name' => 'Rose']]]);
    $this->browser->get(importErrorsUrl($clean))->assertStatus(409)->assertJsonPath('error.code', 'no_error_file');

    foreach (['uploaded', 'importing', 'failed'] as $status) {
        $import = Accounts::plantImport(['election' => $election, 'status' => $status, 'rows' => $bad]);
        $this->browser->get(importErrorsUrl($import))->assertStatus(409)->assertJsonPath('error.code', 'no_error_file');
    }
});

it('answers 404, the same for every case, for an import that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 3)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantImport(['election' => $foreignElection, 'rows' => [['status' => 'invalid', 'reasons' => ['full_name_required'], 'full_name' => null]]]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->get(importErrorsUrl(IMPORT_ERRORS_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(importErrorsUrl($target))))->toBe($unknown);
    }
});

it('answers 401, 403 and 405 [NFR-SEC-04] (scenarios 4, 5 and 7)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $import = Accounts::plantImport(['election' => $election, 'rows' => [['status' => 'invalid', 'reasons' => ['full_name_required'], 'full_name' => null]]]);

    $this->browser->get(importErrorsUrl($import))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->other('POST', importErrorsUrl($import))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->get(importErrorsUrl($import))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 429 above 240 requests an hour from one user [NFR-SEC-05] (scenario 6)', function () {
    [$t, $election] = importErrorsSignedIn($this);
    $import = Accounts::plantImport(['election' => $election, 'rows' => [['status' => 'invalid', 'reasons' => ['full_name_required'], 'full_name' => null]]]);
    foreach (range(1, 240) as $i) {
        $this->browser->get(importErrorsUrl($import))->assertOk();
    }

    $this->browser->get(importErrorsUrl($import))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
