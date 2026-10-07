<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A password must not be the user's email address (rule code `same_as_email`). */
final class SameAsEmail implements ValidationRule
{
    public function __construct(private readonly ?string $email) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->email !== null && is_string($value) && mb_strtolower(trim($value)) === mb_strtolower($this->email)) {
            $fail('same_as_email');
        }
    }
}
