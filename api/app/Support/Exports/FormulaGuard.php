<?php

namespace App\Support\Exports;

/**
 * Protects a text written into a spreadsheet (NFR-SEC-06): a text that a spreadsheet application
 * could take for a formula gets a single quote in front. Shared by every export.
 */
final class FormulaGuard
{
    /** A text that begins with one of these is taken for a formula (tab and carriage return too, as OWASP lists). */
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /** The text as it is written in a cell: prefixed with `'` when it could run as a formula. */
    public static function guard(string $text): string
    {
        return $text !== '' && in_array($text[0], self::TRIGGERS, true) ? "'".$text : $text;
    }
}
