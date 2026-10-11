<?php

/*
 * POST /api/v1/elections/{election}/credential-runs, file part, and the download of a code file (slice 11) —
 * docs/api/credentials/POST-elections-{election}-credential-runs.md and GET-generated-files-{file}-download.md
 * "Slice 11".
 */

use Illuminate\Support\Facades\Crypt;
use Tests\Support\Accounts;
use Tests\Support\Codes;
use Tests\Support\Spreadsheets;
use Tests\Support\Team;

function fileRunUrl(string $election): string
{
    return "/api/v1/elections/{$election}/credential-runs";
}

/** An owner signed in; "4e année" (Rose, Jean), "3e année" (Zoé) and a voter with a formula for a name. Returns [election, g4, g3]. */
function fileRunSetup($test, string $language = 'fr'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'language' => $language, 'title' => 'Conseil']);
    $g4 = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $g3 = Accounts::plantGroup(['election' => $election, 'name' => '3e année']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose Désir', 'group' => $g4]);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Jean Pierre', 'group' => $g4]);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Zoé Louis', 'group' => $g3]);
    Team::signIn($test, $t['a']['owner']);

    return [$election, $g4, $g3];
}

/** The rows of the first file of a kind, decrypted and parsed. */
function fileRunRows($test, string $election, string $kind): array
{
    $row = collect(Accounts::generatedFileRows($election))->firstWhere('kind', $kind);
    $bytes = Crypt::decryptString(file_get_contents($test->credentialsRoot.'/'.$row['stored_file']));
    if ($kind === 'codes_xlsx') {
        return Spreadsheets::read($bytes);
    }
    expect(str_starts_with($bytes, "\xEF\xBB\xBF"))->toBeTrue();

    return array_map('str_getcsv', array_values(array_filter(explode("\n", str_replace("\r\n", "\n", substr($bytes, 3))))));
}

it('writes one Excel file with a row for every voter served, sorted by group then name [FR-CRED-04] (scenario 23)', function () {
    [$election, $g4, $g3] = fileRunSetup($this);

    $response = $this->browser->post(fileRunUrl($election), ['scope' => 'all', 'file' => 'xlsx'])->assertStatus(202);

    $kinds = array_column($response->json('data.files'), 'kind');
    sort($kinds);
    expect($kinds)->toBe(['codes_xlsx', 'slips_pdf', 'slips_pdf']);
    $rows = fileRunRows($this, $election, 'codes_xlsx');
    expect($rows[0])->toBe(['Nom complet', 'Groupe', 'Code', 'Lien'])
        ->and(array_column($rows, 0))->toBe(['Nom complet', 'Zoé Louis', 'Jean Pierre', 'Rose Désir'])
        ->and(array_column($rows, 1))->toBe(['Groupe', '3e année', '4e année', '4e année']);
    foreach (array_slice($rows, 1) as $row) {
        expect($row[2])->toMatch(Codes::PATTERN)
            ->and($row[3])->toBe(rtrim((string) config('app.url'), '/')."/vote/{$election}#{$row[2]}");
    }
});

it('writes a CSV with a byte order mark and the same columns, with English headers for an English election [FR-CRED-04] (scenario 23)', function () {
    [$election] = fileRunSetup($this, 'en');

    $this->browser->post(fileRunUrl($election), ['scope' => 'all', 'file' => 'csv'])->assertStatus(202);

    $rows = fileRunRows($this, $election, 'codes_csv');
    expect($rows[0])->toBe(['Full name', 'Group', 'Code', 'Link'])
        ->and($rows)->toHaveCount(4);
});

it('hashes every code of the file like the slip [FR-CRED-02] (scenario 23)', function () {
    [$election] = fileRunSetup($this);

    $this->browser->post(fileRunUrl($election), ['scope' => 'all', 'file' => 'xlsx'])->assertStatus(202);

    $rows = fileRunRows($this, $election, 'codes_xlsx');
    $byName = array_column(array_slice($rows, 1), 2, 0);
    foreach (Accounts::credentialRows($election) as $row) {
        expect($row['code_hash'])->toBe(Codes::hash($byName[$row['full_name']]));
    }
});

it('quotes a name that could run as a formula [NFR-SEC-06] (scenario 23)', function () {
    [$election, $g4] = fileRunSetup($this);
    Accounts::plantVoter(['election' => $election, 'full_name' => '=HYPERLINK("http://evil.example","x")', 'group' => $g4]);

    $this->browser->post(fileRunUrl($election), ['scope' => 'all', 'file' => 'xlsx'])->assertStatus(202);

    $rows = fileRunRows($this, $election, 'codes_xlsx');
    expect(array_column($rows, 0))->toContain("'=HYPERLINK(\"http://evil.example\",\"x\")")
        ->and(array_filter(array_column($rows, 0), fn ($cell) => str_starts_with($cell, '=')))->toBe([]);
});

it('scopes the file to the group of the run and names it with the group [FR-CRED-04] (scenario 23)', function () {
    [$election, $g4] = fileRunSetup($this);

    $response = $this->browser->post(fileRunUrl($election), ['scope' => 'group', 'group' => $g4, 'file' => 'csv'])->assertStatus(202);

    $file = collect($response->json('data.files'))->firstWhere('kind', 'codes_csv');
    expect($file['group'])->toBe(['id' => $g4, 'name' => '4e année'])
        ->and($file['voter_count'])->toBe(2)
        ->and(fileRunRows($this, $election, 'codes_csv'))->toHaveCount(3);
});

it('downloads the file with its content type and a fixed name [FR-CRED-05] (scenario 23)', function (string $kind, string $format, string $type, string $name) {
    [$election, $g4] = fileRunSetup($this);
    $scope = $format === 'xlsx' ? ['scope' => 'all'] : ['scope' => 'group', 'group' => $g4];
    $response = $this->browser->post(fileRunUrl($election), $scope + ['file' => $format])->assertStatus(202);
    $id = collect($response->json('data.files'))->firstWhere('kind', $kind)['id'];

    $download = $this->browser->get("/api/v1/generated-files/{$id}/download")->assertOk();

    expect($download->headers->get('Content-Type'))->toContain($type)
        ->and($download->headers->get('Content-Disposition'))->toBe("attachment; filename=\"{$name}\"")
        ->and($download->headers->get('Cache-Control'))->toContain('no-store')
        ->and($download->headers->get('X-Content-Type-Options'))->toBe('nosniff');
})->with([
    'xlsx' => ['codes_xlsx', 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'codes-tous.xlsx'],
    'csv' => ['codes_csv', 'csv', 'text/csv', 'codes-4e-annee.csv'],
]);

it('keeps no clear copy of the file on the disk [FR-CRED-05] (scenario 23)', function () {
    [$election] = fileRunSetup($this);

    $this->browser->post(fileRunUrl($election), ['scope' => 'all', 'file' => 'csv'])->assertStatus(202);

    foreach (Codes::storedFiles() as $name) {
        $raw = file_get_contents($this->credentialsRoot.'/'.$name);
        expect($raw)->not->toContain('Rose Désir')->and(Codes::FIND)->not->toBeNull()
            ->and(preg_match(Codes::FIND, $raw))->toBe(0);
    }
});

it('is purged with the other files when the election closes [FR-CRED-05] (scenario 23)', function () {
    [$election] = fileRunSetup($this);
    $this->browser->post(fileRunUrl($election), ['scope' => 'all', 'file' => 'xlsx'])->assertStatus(202);
    Accounts::setElectionStatus($election, 'closed');

    expect(Illuminate\Support\Facades\Artisan::call('files:purge'))->toBe(0);

    expect(Accounts::generatedFileRows($election))->toBe([])
        ->and(Codes::storedFiles())->toBe([]);
});
