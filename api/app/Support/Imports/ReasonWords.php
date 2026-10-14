<?php

namespace App\Support\Imports;

/** The reason codes of a rejected row, in words (`lang/<language>/imports.php`). */
final class ReasonWords
{
    /** Every code the check or the import writes (docs/api/voter-imports/GET-voter-imports-{import}-rows.md). */
    public const CODES = [
        'full_name_required', 'full_name_max', 'group_max', 'identifier_max', 'email_invalid', 'email_max',
        'phone_invalid', 'phone_max', 'group_limit_reached', 'duplicate_identifier_in_file',
        'duplicate_email_in_file', 'identifier_exists', 'email_exists',
    ];

    /**
     * The phrases of the codes joined with `; `. A code nobody knows gets a general phrase, never itself.
     *
     * @param  list<string>|null  $codes
     */
    public static function phrase(?array $codes, string $language): string
    {
        $words = [];

        foreach ($codes ?? [] as $code) {
            $key = in_array($code, self::CODES, true) ? $code : 'unknown';
            $words[] = trans('imports.reasons.'.$key, [], $language);
        }

        return implode('; ', $words);
    }
}
