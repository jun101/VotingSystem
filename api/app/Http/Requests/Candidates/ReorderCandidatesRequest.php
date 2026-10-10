<?php

namespace App\Http\Requests\Candidates;

use App\Http\Requests\Concerns\ReportsRuleCodes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** docs/api/candidates/PUT-ballots-{ballot}-candidates-order.md. */
class ReorderCandidatesRequest extends FormRequest
{
    use ReportsRuleCodes;

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        // `required` refuses an empty array, which is the valid order of a ballot with no candidate.
        $listed = $this->input('candidates') === [] ? ['array'] : ['required', 'array'];

        return [
            'candidates' => ['bail', ...$listed],
            'candidates.*' => ['bail', 'uuid'],
        ];
    }

    /** @return list<string> the UUIDs asked for, in that order, lower-cased */
    public function order(): array
    {
        $listed = $this->validated('candidates');

        return array_values(array_map(
            fn (mixed $uuid): string => strtolower(is_string($uuid) ? $uuid : ''),
            is_array($listed) ? $listed : [],
        ));
    }
}
