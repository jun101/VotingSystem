<?php

namespace App\Http\Requests\Parties;

use App\Models\Party;
use App\Rules\HexColour;
use Illuminate\Contracts\Validation\ValidationRule;

/** docs/api/parties/PATCH-parties-{party}.md: the rules of the creation, every field optional. */
class UpdatePartyRequest extends CreatePartyRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'bail', 'required', 'string', 'max:100'],
            'acronym' => ['sometimes', 'nullable', 'string', 'max:15'],
            'colour' => ['sometimes', 'bail', 'required', new HexColour],
        ];
    }

    protected function electionKey(): mixed
    {
        $party = $this->route('party');

        return $party instanceof Party ? $party->election_id : null;
    }
}
