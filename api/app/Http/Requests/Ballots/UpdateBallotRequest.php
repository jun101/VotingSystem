<?php

namespace App\Http\Requests\Ballots;

use Illuminate\Contracts\Validation\ValidationRule;

/** docs/api/ballots/PATCH-ballots-{ballot}.md: the rules of the creation, every field optional. */
class UpdateBallotRequest extends CreateBallotRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'bail', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'seats' => ['sometimes', 'bail', 'integer', 'min:1', 'max:20'],
            'allow_blank' => ['sometimes', 'boolean'],
        ];
    }
}
