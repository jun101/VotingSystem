<?php

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * An ISO 8601 date and time (`2026-10-12T12:00:00Z`, `2026-10-12T08:00:00-04:00`; with no offset
 * it is read as UTC). Rule code `date`. Free text such as "next monday" is refused.
 */
final class IsoDateTime implements ValidationRule
{
    private const PATTERN = '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})?$/i';

    /** The instant in UTC, or null when the value is not an ISO 8601 date and time. */
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value, $m) !== 1) {
            return null;
        }

        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) ($m[6] ?? 0) > 59) {
            return null;
        }

        try {
            // Whole seconds, as stored; and a year the column and the screens can hold.
            $instant = CarbonImmutable::parse($value, 'UTC')->utc()->startOfSecond();

            return $instant->year >= 1000 && $instant->year <= 9999 ? $instant : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::parse($value) === null) {
            $fail('date');
        }
    }
}
