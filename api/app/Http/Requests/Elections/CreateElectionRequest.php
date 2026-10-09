<?php

namespace App\Http\Requests\Elections;

use App\Enums\CandidateOrder;
use App\Enums\ResultsDisplay;
use App\Http\Requests\Concerns\ReportsRuleCodes;
use App\Http\Requests\Concerns\TrimsInput;
use App\Rules\AfterStart;
use App\Rules\Choice;
use App\Rules\IsoDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** docs/api/elections/POST-elections.md. */
class CreateElectionRequest extends FormRequest
{
    use ReportsRuleCodes;
    use TrimsInput;

    protected function prepareForValidation(): void
    {
        $this->trimFields(['title', 'description', 'timezone'], ['description']);
    }

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'title' => ['bail', 'required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['bail', 'required', new IsoDateTime],
            'ends_at' => ['bail', 'required', new IsoDateTime, new AfterStart($this->bounds(...))],
            'timezone' => ['sometimes', 'bail', 'required', 'timezone'],
            'language' => ['sometimes', 'bail', new Choice(['fr', 'en'])],
            'candidate_order' => ['sometimes', 'bail', new Choice(array_column(CandidateOrder::cases(), 'value'))],
            'results_display' => ['sometimes', 'bail', new Choice(array_column(ResultsDisplay::cases(), 'value'))],
        ];
    }

    /**
     * Start and end as they will be after the change.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    protected function bounds(): array
    {
        return [IsoDateTime::parse($this->input('starts_at')), IsoDateTime::parse($this->input('ends_at'))];
    }

    /**
     * The fields to write: the ones the request carries, dates as UTC.
     *
     * @return array<string, mixed>
     */
    public function attributesToWrite(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = IsoDateTime::parse($validated[$field]);
            }
        }

        return $validated;
    }
}
