<?php

namespace App\Support\Reports;

/**
 * The engine writes the page boxes as `[0.000000 0.000000 612.000000 792.000000]`. The same numbers are written
 * here as `[0 0 612.000000 792.000000   ]`, padded with spaces to the same length so every offset of the
 * cross-reference table stays right: a reader that looks for the page size as `0 0 w h` finds it. Page content
 * is compressed, so only the page dictionaries can match.
 */
final class PlainPageBoxes
{
    public static function apply(string $pdf): string
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
}
