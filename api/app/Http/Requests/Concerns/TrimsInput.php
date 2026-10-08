<?php

namespace App\Http\Requests\Concerns;

/** Strings are trimmed before they are judged; for an optional field, blank means "clear it". */
trait TrimsInput
{
    /**
     * @param  list<string>  $fields  trimmed
     * @param  list<string>  $optional  of those, the ones stored as null when blank
     */
    protected function trimFields(array $fields, array $optional = []): void
    {
        $merge = [];

        foreach ($fields as $field) {
            $value = $this->input($field);

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);
            $merge[$field] = $value === '' && in_array($field, $optional, true) ? null : $value;
        }

        $this->merge($merge);
    }
}
