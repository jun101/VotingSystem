<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The value must be one of a fixed list of strings (rule code `in`).
 *
 * Unlike Laravel's `in`, it also judges an empty or `null` value, which `in` skips: a field
 * that is sent must hold one of the choices.
 */
final class Choice implements ValidationRule
{
    /** Judge the value even when it is empty or null. */
    public bool $implicit = true;

    /** @param  list<string>  $choices */
    public function __construct(private readonly array $choices) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! in_array($value, $this->choices, true)) {
            $fail('in');
        }
    }
}
