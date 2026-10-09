<?php

namespace App\Http\Requests\Elections;

use App\Enums\CandidateOrder;
use App\Enums\ResultsDisplay;
use App\Models\Election;
use App\Rules\AfterStart;
use App\Rules\Choice;
use App\Rules\IsoDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * docs/api/elections/PATCH-elections-{election}.md: every field is optional; the order of the
 * dates is judged on the values as they will be after the change.
 */
class UpdateElectionRequest extends CreateElectionRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        $touchesDates = $this->has('starts_at') || $this->has('ends_at');

        return [
            'title' => ['sometimes', 'bail', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'starts_at' => ['sometimes', 'bail', 'required', new IsoDateTime],
            'ends_at' => $touchesDates
                ? array_values(array_filter([
                    'bail',
                    $this->has('ends_at') ? 'required' : null,
                    new IsoDateTime,
                    new AfterStart($this->bounds(...)),
                ]))
                : [],
            'timezone' => ['sometimes', 'bail', 'required', 'timezone'],
            'language' => ['sometimes', 'bail', new Choice(['fr', 'en'])],
            'candidate_order' => ['sometimes', 'bail', new Choice(array_column(CandidateOrder::cases(), 'value'))],
            'results_display' => ['sometimes', 'bail', new Choice(array_column(ResultsDisplay::cases(), 'value'))],
        ];
    }

    protected function bounds(): array
    {
        $stored = $this->route('election');
        $election = $stored instanceof Election ? $stored : null;

        $start = $this->has('starts_at') ? IsoDateTime::parse($this->input('starts_at')) : ($election === null ? null : CarbonImmutable::instance($election->starts_at)->utc());
        $end = $this->has('ends_at') ? IsoDateTime::parse($this->input('ends_at')) : ($election === null ? null : CarbonImmutable::instance($election->ends_at)->utc());

        return [$start, $end];
    }
}
