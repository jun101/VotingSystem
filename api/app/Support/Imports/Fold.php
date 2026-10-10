<?php

namespace App\Support\Imports;

use Illuminate\Support\Str;

/**
 * Comparison keys for the text of a voter file. The voters table compares an identifier and an
 * email ignoring case and accents (utf8mb4_unicode_ci); the check of a file does the same in
 * PHP, so that a duplicate inside the file is seen as the database would see it.
 */
final class Fold
{
    /** Lower case, accents removed from the Latin letters (other scripts are left as they are). */
    public static function key(string $text): string
    {
        $lower = mb_strtolower(trim($text));

        $plain = preg_replace_callback(
            '/[\x{00C0}-\x{024F}]/u',
            static fn (array $match): string => Str::ascii($match[0]),
            $lower,
        );

        return $plain ?? $lower;
    }

    /** A header name as the mapping proposal compares it: folded, with no space, dash, dot or underscore. */
    public static function header(string $text): string
    {
        return preg_replace('/[\s_.\-]+/u', '', self::key($text)) ?? '';
    }
}
