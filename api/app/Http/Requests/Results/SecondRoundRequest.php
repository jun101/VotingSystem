<?php

namespace App\Http\Requests\Results;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * docs/api/results/POST-elections-{election}-second-round.md: `ballot`, the UUID of a ballot of the
 * election. Whether the ballot exists in this election is judged in the action, under the row lock;
 * here a missing or malformed value answers `ballot: invalid`, the same as an unknown ballot.
 */
class SecondRoundRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'ballot' => ['bail', 'required', 'string', 'uuid'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw ValidationException::withMessages(['ballot' => ['invalid']]);
    }

    public function ballotUuid(): string
    {
        return strtolower($this->string('ballot')->toString());
    }
}
