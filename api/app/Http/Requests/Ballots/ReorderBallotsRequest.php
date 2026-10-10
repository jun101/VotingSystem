<?php

namespace App\Http\Requests\Ballots;

use App\Http\Requests\Concerns\ReportsRuleCodes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** docs/api/ballots/PUT-elections-{election}-ballots-order.md. */
class ReorderBallotsRequest extends FormRequest
{
    use ReportsRuleCodes;

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        // `required` refuses an empty array, which is the valid order of an election with no ballot.
        $listed = $this->input('ballots') === [] ? ['array'] : ['required', 'array'];

        return [
            'ballots' => ['bail', ...$listed],
            'ballots.*' => ['bail', 'uuid'],
        ];
    }

    /** @return list<string> the UUIDs asked for, in that order, lower-cased */
    public function order(): array
    {
        $listed = $this->validated('ballots');

        return array_values(array_map(
            fn (mixed $uuid): string => strtolower(is_string($uuid) ? $uuid : ''),
            is_array($listed) ? $listed : [],
        ));
    }
}
