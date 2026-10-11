<?php

/*
 * What the slip sheet looks like in the PDF (FR-CRED-04, FR-CRED-06): US Letter, 10 slips a page, cut lines, one
 * page per ten voters. The text and the reading order are in CredentialRunTest; the QR payload has its unit test.
 */

use Illuminate\Support\Facades\Crypt;
use Tests\Support\Accounts;
use Tests\Support\Pdfs;
use Tests\Support\Team;

/** An owner signed in, a group of $voters voters, a run done. Returns the decrypted PDF. */
function layoutPdf($test, int $voters): string
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Conseil des élèves']);
    $group = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    foreach (range(1, $voters) as $i) {
        Accounts::plantVoter(['election' => $election, 'full_name' => sprintf('Élève %03d', $i), 'group' => $group]);
    }
    Team::signIn($test, $t['a']['owner']);
    $test->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all'])->assertStatus(202);
    $row = Accounts::generatedFileRows($election)[0];

    return Crypt::decryptString(file_get_contents($test->credentialsRoot.'/'.$row['stored_file']));
}

it('puts 10 slips on a page, on US Letter, one page more for the eleventh [FR-CRED-04] (scenario 1)', function (int $voters, int $pages) {
    $pdf = layoutPdf($this, $voters);

    expect(Pdfs::isLetter($pdf))->toBeTrue()
        ->and(Pdfs::pageCount($pdf))->toBe($pages)
        ->and(Pdfs::codes($pdf))->toHaveCount($voters);
})->with([[1, 1], [10, 1], [11, 2], [30, 3]]);

it('draws the cut lines of every page [FR-CRED-04] (scenario 2)', function () {
    $pdf = layoutPdf($this, 10);

    $content = implode("\n", Pdfs::streams($pdf));

    // A dash pattern is set, and at least five strokes are drawn (four between the rows, one between the columns).
    expect(preg_match('/\]\s*[0-9.]+\s+d(\s|$)/', $content))->toBe(1)
        ->and(preg_match_all('/\sS(\s|$)/', $content))->toBeGreaterThanOrEqual(5);
});

it('draws a QR code on each slip as vectors, no image [FR-CRED-04, FR-CRED-06] (scenario 3)', function () {
    $pdf = layoutPdf($this, 10);

    $content = implode("\n", Pdfs::streams($pdf));

    // Modules are filled rectangles: a version 3 or 4 QR has hundreds; ten slips have thousands.
    expect(preg_match_all('/\sre(\s|$)/', $content))->toBeGreaterThan(1000)
        ->and(preg_match('#/Subtype\s*/Image#', $pdf))->toBe(0);
});
