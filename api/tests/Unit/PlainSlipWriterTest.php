<?php

use App\Support\Credentials\PlainSlipWriter;
use App\Support\Credentials\Slip;
use App\Support\Credentials\SlipSheet;
use Carbon\CarbonImmutable;

function slipSheetOf(int $count, string $name = 'Élève Test'): SlipSheet
{
    $slips = [];

    for ($i = 1; $i <= $count; $i++) {
        $slips[] = new Slip($name.' '.$i, 'Groupe A', sprintf('ABCDE-%05d', $i));
    }

    return new SlipSheet(
        institutionName: 'Collège Saint-Jean',
        electionTitle: 'Élection du conseil',
        votingAddress: 'https://vote.example.test/vote/0b6f2a3e-1111-4222-8333-444455556666',
        startsAt: CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        endsAt: CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'),
        timezone: 'America/Port-au-Prince',
        language: 'fr',
        groupName: 'Groupe A',
        part: 1,
        parts: 1,
        slips: $slips,
    );
}

it('writes a PDF on US Letter', function () {
    $pdf = (new PlainSlipWriter)->write(slipSheetOf(1));

    expect($pdf)->toStartWith('%PDF-1.4')
        ->and($pdf)->toContain('/MediaBox [0 0 612 792]')
        ->and(rtrim($pdf))->toEndWith('%%EOF');
});

it('puts ten slips on a page, so 25 slips give three pages', function () {
    $writer = new PlainSlipWriter;

    expect(substr_count($writer->write(slipSheetOf(10)), '/Type /Page /'))->toBe(1)
        ->and(substr_count($writer->write(slipSheetOf(11)), '/Type /Page /'))->toBe(2)
        ->and(substr_count($writer->write(slipSheetOf(25)), '/Type /Page /'))->toBe(3)
        ->and($writer->write(slipSheetOf(25)))->toContain('/Count 3');
});

it('writes the code as one string and the texts in reading order', function () {
    $pdf = (new PlainSlipWriter)->write(slipSheetOf(1));

    expect($pdf)->toContain('(ABCDE-00001) Tj');

    $order = ["(Coll\xE8ge Saint-Jean)", "(\xC9lection du conseil)", "(\xC9l\xE8ve Test 1)", '(Groupe A)', '(ABCDE-00001)', '(https://vote.example.test/vote/', '(Vote du 01/11/2026'];
    $last = -1;

    foreach ($order as $needle) {
        $at = strpos($pdf, $needle);

        expect($at)->not->toBeFalse("missing {$needle}")
            ->and($at)->toBeGreaterThan($last);
        $last = $at;
    }
});

it('encodes accents as Windows-1252 bytes, not UTF-8', function () {
    $pdf = (new PlainSlipWriter)->write(slipSheetOf(1));

    expect($pdf)->toContain("\xC9l\xE8ve")
        ->and($pdf)->not->toContain("\xC3\x89");
});

it('writes a page for a file with no slip', function () {
    expect(substr_count((new PlainSlipWriter)->write(slipSheetOf(0)), '/Type /Page /'))->toBe(1);
});
