<?php

namespace App\Http\Requests\Ballots;

use App\Http\Requests\Concerns\ReportsRuleCodes;
use App\Http\Requests\Concerns\TrimsInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** docs/api/ballots/POST-elections-{election}-ballots.md. */
class CreateBallotRequest extends FormRequest
{
    use ReportsRuleCodes;
    use TrimsInput;

    protected function prepareForValidation(): void
    {
        $this->trimFields(['title', 'description'], ['description']);
    }

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'title' => ['bail', 'required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'seats' => ['bail', 'integer', 'min:1', 'max:20'],
            'allow_blank' => ['boolean'],
        ];
    }

    /**
     * The fields to write: the ones the request carries and the model accepts.
     *
     * @return array<string, mixed>
     */
    public function attributesToWrite(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return $validated;
    }
}
