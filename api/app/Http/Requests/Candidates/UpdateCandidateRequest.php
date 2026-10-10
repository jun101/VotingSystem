<?php

namespace App\Http\Requests\Candidates;

use App\Models\Candidate;
use Illuminate\Contracts\Validation\ValidationRule;

/** docs/api/candidates/PATCH-candidates-{candidate}.md: the rules of the creation, every field optional, and `ballot`. */
class UpdateCandidateRequest extends CreateCandidateRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'bail', 'required', 'string', 'max:80'],
            'last_name' => ['sometimes', 'bail', 'required', 'string', 'max:80'],
            'sex' => ['sometimes', 'bail', 'required', 'in:male,female'],
            'party' => ['sometimes', 'bail', 'nullable', 'uuid'],
            'slogan' => ['sometimes', 'nullable', 'string', 'max:80'],
            'biography' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'ballot' => ['sometimes', 'bail', 'required', 'uuid'],
        ];
    }

    protected function wantsBallot(): bool
    {
        return true;
    }

    protected function electionKey(): mixed
    {
        $candidate = $this->route('candidate');

        return $candidate instanceof Candidate ? $candidate->election_id : null;
    }
}
