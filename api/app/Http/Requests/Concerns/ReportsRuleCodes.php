<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * For a request whose endpoint file names the failed rules by Laravel's own names (`email`,
 * `in`, `timezone`, `between`), with `format` for a pattern and `taken` for a duplicate.
 * (The sign-in endpoints of slice 02 say `invalid` for a bad address and a bad choice; their
 * requests keep the renderer's shared mapping.)
 */
trait ReportsRuleCodes
{
    protected function failedValidation(Validator $validator): never
    {
        $codes = [];
        /** @var array<string, array<string, mixed>> $failed */
        $failed = $validator->failed();

        foreach ($failed as $field => $rules) {
            foreach (array_keys($rules) as $rule) {
                $codes[$field][] = match (Str::snake(class_basename((string) $rule))) {
                    'regex' => 'format',
                    'choice' => 'in',
                    'iso_date_time' => 'date',
                    'unique' => 'taken',
                    default => Str::snake(class_basename((string) $rule)),
                };
            }
        }

        throw ValidationException::withMessages($codes);
    }
}
