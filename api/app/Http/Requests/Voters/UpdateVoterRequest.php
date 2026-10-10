<?php

namespace App\Http\Requests\Voters;

use App\Models\Voter;
use Illuminate\Contracts\Validation\ValidationRule;

/** docs/api/voters/PATCH-voters-{voter}.md: the rules of the creation, every field optional. */
class UpdateVoterRequest extends CreateVoterRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'bail', 'required', 'string', 'max:150'],
            'group' => ['sometimes', 'nullable', 'string', 'max:100'],
            'identifier' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }

    protected function electionKey(): mixed
    {
        $voter = $this->route('voter');

        return $voter instanceof Voter ? $voter->election_id : null;
    }

    protected function ownKey(): mixed
    {
        $voter = $this->route('voter');

        return $voter instanceof Voter ? $voter->getKey() : null;
    }
}
