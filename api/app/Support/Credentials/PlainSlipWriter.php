<?php

namespace App\Support\Credentials;

use Illuminate\Support\Facades\Lang;

/**
 * The first slip layout: a hand-written PDF, US Letter, Helvetica core font, 2 columns by 5
 * rows (10 slips a page) with dashed cut lines. Each text is its own `(...) Tj` string, in
 * reading order: institution, election, voter, group, code (one string), voting address, voting
 * period. No QR code yet: the TCPDF writer replaces this class behind `SlipWriter`.
 */
final class PlainSlipWriter implements SlipWriter
{
    private const PAGE_WIDTH = 612;

    private const PAGE_HEIGHT = 792;

    private const MARGIN = 36;

    private const COLUMNS = 2;

    private const ROWS = 5;

    public const PER_PAGE = self::COLUMNS * self::ROWS;

    /** Longest text printed on a line before it is cut (the address and the period are never cut). */
    private const MAX_CHARS = 44;

    public function write(SlipSheet $sheet): string
    {
        $pages = array_chunk($sheet->slips, self::PER_PAGE);

        if ($pages === []) {
            $pages = [[]];
        }

        $period = $this->period($sheet);

        // 1 catalog, 2 pages, 3 Helvetica, 4 Helvetica-Bold, then a page and its content per page.
        $objects = [];
        $kids = [];

        foreach ($pages as $index => $slips) {
            $pageNumber = 5 + 2 * $index;
            $kids[] = $pageNumber.' 0 R';
            $content = $this->content($sheet, $slips, $period);

            $objects[$pageNumber] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::PAGE_WIDTH.' '.self::PAGE_HEIGHT.'] '
                .'/Contents '.($pageNumber + 1).' 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> >>';
            $objects[$pageNumber + 1] = '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream";
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /** @param  list<Slip>  $slips */
    private function content(SlipSheet $sheet, array $slips, string $period): string
    {
        $cellWidth = (self::PAGE_WIDTH - 2 * self::MARGIN) / self::COLUMNS;
        $cellHeight = (self::PAGE_HEIGHT - 2 * self::MARGIN) / self::ROWS;
        $out = "0.5 w [3 3] 0 d\n";

        foreach ($slips as $i => $slip) {
            $column = $i % self::COLUMNS;
            $row = intdiv($i, self::COLUMNS);
            $left = self::MARGIN + $column * $cellWidth;
            $top = self::PAGE_HEIGHT - self::MARGIN - $row * $cellHeight;

            // The cut line around the slip.
            $out .= sprintf("%.2f %.2f %.2f %.2f re S\n", $left, $top - $cellHeight, $cellWidth, $cellHeight);

            $x = $left + 10;
            $out .= $this->text('F1', 8, $x, $top - 16, $this->cut($sheet->institutionName));
            $out .= $this->text('F2', 10, $x, $top - 32, $this->cut($sheet->electionTitle));
            $out .= $this->text('F2', 11, $x, $top - 54, $this->cut($slip->name));
            $out .= $this->text('F1', 9, $x, $top - 70, $this->cut($slip->group));
            $out .= $this->text('F2', 18, $x, $top - 96, $slip->code);
            $out .= $this->text('F1', 7, $x, $top - 114, $sheet->votingAddress);
            $out .= $this->text('F1', 7, $x, $top - 126, $period);
        }

        return $out;
    }

    private function text(string $font, int $size, float $x, float $y, string $text): string
    {
        return sprintf("BT /%s %d Tf %.2f %.2f Td (%s) Tj ET\n", $font, $size, $x, $y, $this->escape($text));
    }

    /** Windows-1252 bytes with the three characters a PDF string keeps for itself escaped. */
    private function escape(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function cut(string $text): string
    {
        return mb_strimwidth($text, 0, self::MAX_CHARS, '...', 'UTF-8');
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
