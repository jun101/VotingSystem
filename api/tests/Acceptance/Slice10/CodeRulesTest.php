<?php

/*
 * What a code is and where it may be (FR-CRED-02, FR-CRED-05, FR-CRED-06; docs/slices/10-codes-and-slips.md "Rules"):
 * the alphabet, the hash, nothing in clear in the database, in the stored file, in a log or in a URL.
 */

use Illuminate\Support\Facades\Crypt;
use Tests\Support\Accounts;
use Tests\Support\Codes;
use Tests\Support\Pdfs;
use Tests\Support\Team;

/** An owner of A signed in, an election with one group of 40 voters, a run done. Returns [election, group, pdf, names]. */
function rulesRun($test, int $voters = 40): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Conseil des élèves']);
    $group = Accounts::plantGroup(['election' => $election, 'name' => '6e année']);
    $names = [];
    foreach (range(1, $voters) as $i) {
        $names[] = sprintf('Votant %02d', $i);
        Accounts::plantVoter(['election' => $election, 'full_name' => end($names), 'group' => $group]);
    }
    Team::signIn($test, $t['a']['owner']);
    $test->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all'])->assertStatus(202);
    $row = Accounts::generatedFileRows($election)[0];
    $pdf = Crypt::decryptString(file_get_contents($test->credentialsRoot.'/'.$row['stored_file']));

    return [$election, $group, $pdf, $names];
}

it('draws codes of ten characters from the alphabet without look-alikes, all different [FR-CRED-02] (scenario 1)', function () {
    [$election, $group, $pdf] = rulesRun($this);

    $codes = Pdfs::codes($pdf);

    expect($codes)->toHaveCount(40)
        ->and(array_unique($codes))->toHaveCount(40);
    foreach ($codes as $code) {
        expect($code)->toMatch(Codes::PATTERN)
            ->and($code)->not->toMatch('/[01OIL]/');
    }
});

it('stores only the HMAC of each code, never the code [FR-CRED-02] (scenario 2)', function () {
    [$election, $group, $pdf, $names] = rulesRun($this);

    $codes = Pdfs::codesByName($pdf, $names);

    $rows = Accounts::credentialRows($election);
    expect($rows)->toHaveCount(40);
    foreach ($rows as $row) {
        expect(strlen($row['code_hash']))->toBe(32)
            ->and($row['code_hash'])->toBe(Codes::hash($codes[$row['full_name']]))
            ->and($row['reissue_count'])->toBe(0);
        foreach ($row as $value) {
            if (is_string($value)) {
                expect($value)->not->toContain($codes[$row['full_name']])
                    ->and($value)->not->toContain(str_replace('-', '', $codes[$row['full_name']]));
            }
        }
    }
});

it('hashes the same code the same way whatever its case or hyphen [FR-CRED-02] (scenario 2)', function () {
    expect(Codes::hash('k7mp4-qx9rt'))->toBe(Codes::hash('K7MP4QX9RT'))
        ->and(Codes::hash('K7MP4-QX9RT'))->not->toBe(Codes::hash('K7MP4-QX9RS'));
});

it('keeps the PDF encrypted on the private disk under a random name, with no code in clear [FR-CRED-05] (scenario 3)', function () {
    [$election, $group, $pdf] = rulesRun($this);

    $files = Codes::storedFiles();
    $row = Accounts::generatedFileRows($election)[0];

    expect($files)->toBe([$row['stored_file']])
        ->and($row['stored_file'])->toMatch(UUID_V4);
    $raw = file_get_contents($this->credentialsRoot.'/'.$row['stored_file']);
    expect(Pdfs::isPdf($raw))->toBeFalse()
        ->and(Pdfs::codes($raw))->toBe([])
        ->and($raw)->not->toContain('Votant 01')
        ->and($row['size_bytes'])->toBe(strlen($pdf));
});

it('writes no code, no voter name and no PDF text to any log [FR-CRED-02, NFR-SEC-02] (scenario 4)', function () {
    $before = [];
    foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
        $before[$file] = filesize($file);
    }
    [$election, $group, $pdf, $names] = rulesRun($this, 12);
    $codes = Pdfs::codes($pdf);

    $logged = '';
    foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
        $logged .= (string) substr((string) file_get_contents($file), $before[$file] ?? 0);
    }

    foreach ($codes as $code) {
        expect($logged)->not->toContain($code)->and($logged)->not->toContain(str_replace('-', '', $code));
    }
    expect($logged)->not->toContain('Votant 01')->and($logged)->not->toContain('Conseil des élèves');
});

it('writes the voting address without the code, the code being only in the QR fragment [FR-CRED-06] (scenario 5)', function () {
    [$election, $group, $pdf] = rulesRun($this, 3);

    $text = Pdfs::text($pdf);

    expect($text)->toContain("/vote/{$election}")
        ->and($text)->not->toMatch('#/vote/[0-9a-f-]{36}\#[A-Z2-9]#')
        ->and($text)->not->toMatch('#/vote/[0-9a-f-]{36}[?/][^\s]*[A-Z2-9]{5}-?[A-Z2-9]{5}#');
});

it('gives different codes on two runs for different voters, never the same code twice in an election [FR-CRED-02] (scenario 1)', function () {
    [$election, $group, $pdf] = rulesRun($this, 15);
    $first = Pdfs::codes($pdf);
    foreach (range(1, 15) as $i) {
        Accounts::plantVoter(['election' => $election, 'full_name' => "Tard {$i}", 'group' => $group]);
    }

    $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all'])->assertStatus(202);

    $second = [];
    foreach (Accounts::generatedFileRows($election) as $row) {
        $second = array_merge($second, Pdfs::codes(Crypt::decryptString(file_get_contents($this->credentialsRoot.'/'.$row['stored_file']))));
    }
    expect(array_unique($second))->toHaveCount(30)
        ->and(Accounts::credentialRows($election))->toHaveCount(30);
    expect(count(array_unique(array_column(Accounts::credentialRows($election), 'code_hash'), SORT_REGULAR)))->toBe(30);
    expect(count(array_intersect($first, array_diff($second, $first))))->toBe(0);
});
