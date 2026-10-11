<?php

use App\Support\Credentials\Slip;
use App\Support\Credentials\SlipQrPayload;
use App\Support\Credentials\SlipSheet;
use App\Support\Credentials\TcpdfSlipWriter;
use Carbon\CarbonImmutable;
use Tests\Support\Pdfs;

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
    $pdf = (new TcpdfSlipWriter)->write(slipSheetOf(1));

    expect($pdf)->toStartWith('%PDF-')
        ->and(Pdfs::isLetter($pdf))->toBeTrue()
        ->and(rtrim($pdf))->toEndWith('%%EOF');
});

it('puts ten slips on a page, so 25 slips give three pages', function () {
    $writer = new TcpdfSlipWriter;

    expect(Pdfs::pageCount($writer->write(slipSheetOf(10))))->toBe(1)
        ->and(Pdfs::pageCount($writer->write(slipSheetOf(11))))->toBe(2)
        ->and(Pdfs::pageCount($writer->write(slipSheetOf(25))))->toBe(3);
});

it('writes the code as one string and the texts in reading order', function () {
    $tokens = array_map('trim', Pdfs::tokens((new TcpdfSlipWriter)->write(slipSheetOf(1))));

    expect($tokens)->toContain('ABCDE-00001');

    $order = ['Collège Saint-Jean', 'Élection du conseil', 'Élève Test 1', 'Groupe A', 'ABCDE-00001', 'https://vote.example.test/vote/0b6f2a3e-1111-4222-8333-444455556666'];
    $last = -1;

    foreach ($order as $needle) {
        $at = array_search($needle, $tokens, true);

        expect($at)->not->toBeFalse("missing {$needle}")
            ->and($at)->toBeGreaterThan($last);
        $last = $at;
    }

    $period = array_values(array_filter($tokens, fn (string $t) => str_contains($t, '01/11/2026')));
    expect($period)->toHaveCount(1)
        ->and(array_search($period[0], $tokens, true))->toBeGreaterThan($last);
});

it('keeps the code out of the voting address text', function () {
    $tokens = Pdfs::tokens((new TcpdfSlipWriter)->write(slipSheetOf(1)));
    $address = array_values(array_filter($tokens, fn (string $t) => str_starts_with(trim($t), 'https://')));

    expect($address)->toHaveCount(1)
        ->and($address[0])->not->toContain('ABCDE');
});

it('keeps accents readable', function () {
    $text = Pdfs::text((new TcpdfSlipWriter)->write(slipSheetOf(1)));

    expect($text)->toContain('Élève Test 1')->and($text)->toContain('Collège');
});

it('draws a dashed cut line and the QR code as rectangles, no image', function () {
    $pdf = (new TcpdfSlipWriter)->write(slipSheetOf(1));
    $content = implode("\n", Pdfs::streams($pdf));

    expect(preg_match('/\]\s*[0-9.]+\s+d(\s|$)/', $content))->toBe(1)
        ->and(preg_match_all('/\sre(\s|$)/', $content))->toBeGreaterThan(100)
        ->and(preg_match('#/Subtype\s*/Image#', $pdf))->toBe(0);
});

it('carries the title Coupons and no author, subject or voter data in the document information', function () {
    $pdf = (new TcpdfSlipWriter)->write(slipSheetOf(1));
    $info = (string) preg_replace('/[\x00]/', '', substr($pdf, (int) strpos($pdf, '/Title')));
    $info = substr($info, 0, (int) strpos($info, '>>'));

    expect($info)->toContain('Coupons')
        ->and($info)->not->toContain('/Author')
        ->and($info)->not->toContain('/Subject')
        ->and($info)->not->toContain('ABCDE')
        ->and($info)->not->toContain('Test');
});

it('keeps the cross-reference table right after the page boxes are written plainly', function () {
    $pdf = (new TcpdfSlipWriter)->write(slipSheetOf(1));

    preg_match('/startxref\s+(\d+)/', $pdf, $m);
    expect(substr($pdf, (int) $m[1], 4))->toBe('xref');

    preg_match('/xref\s+0 \d+\s+0000000000 65535 f\s+(\d{10}) 00000 n/', $pdf, $first);
    expect(substr($pdf, (int) $first[1], 7))->toBe('1 0 obj');
});

it('writes a page for a file with no slip', function () {
    expect(Pdfs::pageCount((new TcpdfSlipWriter)->write(slipSheetOf(0))))->toBe(1);
});

it('builds the QR payload as the voting address, a hash and the code as shown', function () {
    expect(SlipQrPayload::for('https://vote.example.test/vote/abc', 'K7MP4-QX9RT'))
        ->toBe('https://vote.example.test/vote/abc#K7MP4-QX9RT');
});

it('keeps no state between two files', function () {
    $writer = new TcpdfSlipWriter;
    $first = Pdfs::pageCount($writer->write(slipSheetOf(11)));
    $second = Pdfs::pageCount($writer->write(slipSheetOf(1)));

    expect($first)->toBe(2)->and($second)->toBe(1);
});
