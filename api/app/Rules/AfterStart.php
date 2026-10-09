<?php

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The end must be after the start, as they will be once the request is applied (rule code
 * `after_start`). It is judged even when the request does not carry the end, since moving the
 * start alone can break the order. Dates that are not valid are left to `IsoDateTime`.
 */
final class AfterStart implements ValidationRule
{
    public bool $implicit = true;

    /** @param  Closure(): array{0: CarbonImmutable|null, 1: CarbonImmutable|null}  $bounds start and end after the change */
    public function __construct(private readonly Closure $bounds) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        [$start, $end] = ($this->bounds)();

        if ($start !== null && $end !== null && ! $end->greaterThan($start)) {
            $fail('after_start');
        }
    }
}
