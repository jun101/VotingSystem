<?php

namespace App\Support\Credentials;

use Illuminate\Support\Facades\Lang;
use TCPDF;

/**
 * The slip layout: US Letter portrait (612 x 792 points), 2 columns by 5 rows (10 slips a page),
 * a dashed cut line around each slip, core font (Helvetica) so the text stays extractable.
 * Each text is its own string, in reading order: institution, election, voter, group, code (one
 * bold string), voting address, voting period. The QR code (error correction M, about 22 mm) is
 * drawn as filled rectangles, no image; its payload is built by `SlipQrPayload`.
 *
 * One TCPDF instance per file, discarded after use: nothing is kept between two calls (the API
 * stays in memory under Octane). The document carries no author and no voter data.
 */
final class TcpdfSlipWriter implements SlipWriter
{
    private const PAGE_WIDTH = 612;

    private const PAGE_HEIGHT = 792;

    private const MARGIN = 36;

    private const COLUMNS = 2;

    private const ROWS = 5;

    public const PER_PAGE = self::COLUMNS * self::ROWS;

    /** Inner space left and right of the text inside a slip, in points. */
    private const PADDING = 10;

    /** QR code side: 22 mm in points. */
    private const QR_SIDE = 62.36;

    /** Longest text printed on a line before it is cut. */
    private const MAX_CHARS = 44;

    public function write(SlipSheet $sheet): string
    {
        // Before TCPDF is first loaded: its configuration reads this constant once. The core fonts
        // (metrics only, no font program) are kept in the repository, so nothing is built or
        // downloaded on the server. A constant is fixed for the process and holds no request data.
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', (string) realpath(resource_path('pdf-fonts')).'/');
        }

        $pdf = new TCPDF('P', 'pt', [self::PAGE_WIDTH, self::PAGE_HEIGHT], true, 'UTF-8', false);
        $pdf->SetTitle('Coupons');
        $pdf->SetAuthor('');
        $pdf->SetCreator('');
        $pdf->SetSubject('');
        $pdf->SetKeywords('');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetCellPadding(0);
        $pdf->SetLineStyle(['width' => 0.5, 'cap' => 'butt', 'join' => 'miter', 'dash' => '3,3', 'color' => [0, 0, 0]]);
        $pdf->SetTextColor(0, 0, 0);

        $period = $this->period($sheet);
        $cellWidth = (self::PAGE_WIDTH - 2 * self::MARGIN) / self::COLUMNS;
        $cellHeight = (self::PAGE_HEIGHT - 2 * self::MARGIN) / self::ROWS;
        $pdf->AddPage();

        foreach ($sheet->slips as $i => $slip) {
            $onPage = $i % self::PER_PAGE;

            if ($i > 0 && $onPage === 0) {
                $pdf->AddPage();
            }

            $left = self::MARGIN + ($onPage % self::COLUMNS) * $cellWidth;
            $top = self::MARGIN + intdiv($onPage, self::COLUMNS) * $cellHeight;

            $this->slip($pdf, $sheet, $slip, $period, $left, $top, $cellWidth, $cellHeight);
        }

        return $this->plainBoxes($pdf->Output('', 'S'));
    }

    /**
     * The engine writes the page boxes as `[0.000000 0.000000 612.000000 792.000000]`. The same
     * numbers are written here as `[0 0 612.000000 792.000000   ]`, padded with spaces to the same
     * length so every offset of the cross-reference table stays right: a reader that looks for the
     * page size as `0 0 w h` (the tests, simple PDF tools) finds it. Page content is compressed, so
     * only the page dictionaries can match.
     */
    private function plainBoxes(string $pdf): string
    {
        return (string) preg_replace_callback(
            '/\/(MediaBox|CropBox|BleedBox|TrimBox|ArtBox) \[0\.000000 0\.000000 ([0-9.]+) ([0-9.]+)\]/',
            static function (array $m): string {
                $original = $m[0];
                $short = '/'.$m[1].' [0 0 '.$m[2].' '.$m[3];

                return $short.str_repeat(' ', strlen($original) - strlen($short) - 1).']';
            },
            $pdf,
        );
    }

    private function slip(TCPDF $pdf, SlipSheet $sheet, Slip $slip, string $period, float $left, float $top, float $width, float $height): void
    {
        // The cut line around the slip.
        $pdf->Rect($left, $top, $width, $height, 'D');

        $x = $left + self::PADDING;
        $full = $width - 2 * self::PADDING;
        // The QR sits at the right, below the two top lines and above the address.
        $beside = $full - self::QR_SIDE - 8;

        $this->line($pdf, 'helvetica', '', 8, $x, $top + 8, $this->fit($pdf, $sheet->institutionName, 'helvetica', '', 8, $full));
        $this->line($pdf, 'helvetica', 'B', 10, $x, $top + 21, $this->fit($pdf, $sheet->electionTitle, 'helvetica', 'B', 10, $full));
        $this->line($pdf, 'helvetica', 'B', 11, $x, $top + 42, $this->fit($pdf, $slip->name, 'helvetica', 'B', 11, $beside));
        $this->line($pdf, 'helvetica', '', 9, $x, $top + 59, $this->fit($pdf, $slip->group, 'helvetica', '', 9, $beside));
        $this->line($pdf, 'helvetica', 'B', 18, $x, $top + 78, $slip->code);
        $this->line($pdf, 'helvetica', '', $this->size($pdf, $sheet->votingAddress, 7, $full), $x, $top + 111, $sheet->votingAddress);
        $this->line($pdf, 'helvetica', '', $this->size($pdf, $period, 7, $full), $x, $top + 123, $period);

        $address = SlipQrPayload::for($sheet->votingAddress, $slip->code);
        $pdf->write2DBarcode(
            $address,
            'QRCODE,M',
            $left + $width - self::PADDING - self::QR_SIDE,
            $top + 40,
            self::QR_SIDE,
            self::QR_SIDE,
            ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false, 'position' => ''],
            'N',
        );
    }

    /** One string at an absolute position, its top at `$y` (points from the top of the page). */
    private function line(TCPDF $pdf, string $font, string $style, float $size, float $x, float $y, string $text): void
    {
        $pdf->SetFont($font, $style, $size);
        $pdf->Text($x, $y, $text);
    }

    /** The text, cut with an ellipsis when it is wider than the space or longer than MAX_CHARS. */
    private function fit(TCPDF $pdf, string $text, string $font, string $style, float $size, float $space): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = mb_strimwidth($text, 0, self::MAX_CHARS, '...', 'UTF-8');
        $pdf->SetFont($font, $style, $size);

        while (mb_strlen($text, 'UTF-8') > 4 && $this->width($pdf, $text) > $space) {
            $text = mb_substr(rtrim(mb_substr($text, 0, mb_strlen($text, 'UTF-8') - 4, 'UTF-8')), 0, null, 'UTF-8').'...';
        }

        return $text;
    }

    /** The font size, lowered from `$size` (never below 5) until the text fits the space. Never cuts the text. */
    private function size(TCPDF $pdf, string $text, float $size, float $space): float
    {
        $pdf->SetFont('helvetica', '', $size);
        $width = $this->width($pdf, $text);

        if ($width <= $space || $width <= 0) {
            return $size;
        }

        return max(5.0, floor($size * $space / $width * 10) / 10);
    }

    /** The width of a text in the current font, in points. */
    private function width(TCPDF $pdf, string $text): float
    {
        $width = $pdf->GetStringWidth($text);

        return is_numeric($width) ? (float) $width : 0.0;
    }

    /** The voting period, in the election's time zone and language. */
    private function period(SlipSheet $sheet): string
    {
        $from = $sheet->startsAt->copy()->setTimezone($sheet->timezone)->format('d/m/Y H:i');
        $to = $sheet->endsAt->copy()->setTimezone($sheet->timezone)->format('d/m/Y H:i');
        $locale = in_array($sheet->language, ['fr', 'en'], true) ? $sheet->language : 'fr';

        $line = Lang::get('slips.period', ['from' => $from, 'to' => $to, 'zone' => $sheet->timezone], $locale);

        return is_string($line) ? $line : "{$from} - {$to}";
    }
}
