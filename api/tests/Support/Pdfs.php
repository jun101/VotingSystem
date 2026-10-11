<?php

namespace Tests\Support;

/**
 * Reads back the PDFs of slips (slice 10) without a PDF library: counts the pages, reads the page size and gets the
 * text strings out of the (inflated) content streams in the order they were written.
 *
 * Part of the acceptance harness: it is not edited when a slice is coded.
 */
final class Pdfs
{
    /** A one-page PDF with one line of text, for a planted file. */
    public static function sample(string $text = 'Coupon'): string
    {
        $stream = "BT /F1 12 Tf 72 700 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $out = "%PDF-1.4\n";
        foreach ($objects as $i => $body) {
            $out .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
        }

        return $out."trailer\n<< /Root 1 0 R /Size 6 >>\n%%EOF\n";
    }

    public static function isPdf(string $bytes): bool
    {
        return str_starts_with($bytes, '%PDF-');
    }

    public static function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $pdf);
    }

    /** True when every page is US Letter, 612 x 792 points. */
    public static function isLetter(string $pdf): bool
    {
        if (preg_match_all('#/MediaBox\s*\[\s*0\s+0\s+([0-9.]+)\s+([0-9.]+)\s*\]#', $pdf, $m) < 1) {
            return false;
        }
        foreach ($m[1] as $i => $width) {
            if (abs((float) $width - 612.0) > 0.5 || abs((float) $m[2][$i] - 792.0) > 0.5) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every text string of the document, in writing order.
     *
     * @return list<string>
     */
    public static function tokens(string $pdf): array
    {
        $tokens = [];
        foreach (self::contents($pdf) as $content) {
            if (preg_match_all('/\[((?:[^\]\\\\]|\\\\.)*)\]\s*TJ|(\((?:\\\\.|[^\\\\()])*\)|<[0-9A-Fa-f\s]+>)\s*Tj/s', $content, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $text = '';
                    $source = ($hit[1] ?? '') !== '' ? $hit[1] : ($hit[2] ?? '');
                    if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)|<[0-9A-Fa-f\s]+>/s', $source, $parts)) {
                        foreach ($parts[0] as $part) {
                            $text .= self::decode($part);
                        }
                    }
                    if (trim($text) !== '') {
                        $tokens[] = $text;
                    }
                }
            }
        }

        return $tokens;
    }

    /** All the text in one string, tokens separated by a line feed. */
    public static function text(string $pdf): string
    {
        return implode("\n", self::tokens($pdf));
    }

    /**
     * The code printed on each voter's slip: a code belongs to the last voter name read before it.
     *
     * @param  list<string>  $names
     * @return array<string, string> name => code
     */
    public static function codesByName(string $pdf, array $names): array
    {
        $out = [];
        $current = null;
        foreach (self::tokens($pdf) as $token) {
            $token = trim($token);
            if (in_array($token, $names, true)) {
                $current = $token;
            } elseif ($current !== null && preg_match(Codes::PATTERN, $token) === 1) {
                $out[$current] = $token;
                $current = null;
            }
        }

        return $out;
    }

    /** Every code read in the document, in order. */
    public static function codes(string $pdf): array
    {
        preg_match_all(Codes::FIND, self::text($pdf), $m);

        return $m[0];
    }

    /** @return list<string> the inflated content of every stream that can be read */
    private static function contents(string $pdf): array
    {
        $out = [];
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
            foreach ($m[1] as $stream) {
                $inflated = @gzuncompress($stream);
                $out[] = $inflated === false ? $stream : $inflated;
            }
        }

        return $out;
    }

    private static function decode(string $part): string
    {
        if ($part[0] === '<') {
            $hex = preg_replace('/\s+/', '', substr($part, 1, -1)) ?? '';
            $bin = (string) hex2bin(strlen($hex) % 2 === 0 ? $hex : $hex.'0');
            if (str_starts_with($bin, "\xFE\xFF")) {
                return mb_convert_encoding(substr($bin, 2), 'UTF-8', 'UTF-16BE');
            }

            return mb_convert_encoding($bin, 'UTF-8', 'Windows-1252');
        }

        $raw = substr($part, 1, -1);
        $raw = preg_replace_callback('/\\\\([0-7]{1,3}|.)/s', function (array $x): string {
            $c = $x[1];
            if (ctype_digit($c[0])) {
                return chr(octdec($c) & 0xFF);
            }

            return ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C"][$c] ?? $c;
        }, $raw) ?? $raw;

        return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
}
