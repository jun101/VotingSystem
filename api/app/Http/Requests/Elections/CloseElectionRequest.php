<?php

namespace App\Http\Requests\Elections;

use App\Http\Requests\Concerns\ReportsRuleCodes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * docs/api/elections/POST-elections-{election}-close.md: the typed confirmation. That it equals the title
 * is judged in the controller, under the row lock, after the status.
 */
class CloseElectionRequest extends FormRequest
{
    use ReportsRuleCodes;

    protected function prepareForValidation(): void
    {
        $value = $this->input('confirmation');

        if (is_string($value)) {
            $this->merge(['confirmation' => trim($value)]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'confirmation' => ['bail', 'required', 'string'],
        ];
    }

    public function confirmation(): string
    {
        return $this->string('confirmation')->toString();
    }
}
