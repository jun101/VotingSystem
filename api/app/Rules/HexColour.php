<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** `#` and six hexadecimal digits, in any case (rule code `hex_colour`). */
final class HexColour implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/\A#[0-9A-Fa-f]{6}\z/', $value) !== 1) {
            $fail('hex_colour');
        }
    }
}
