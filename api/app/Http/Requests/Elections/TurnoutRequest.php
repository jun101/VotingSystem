<?php

namespace App\Http\Requests\Elections;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** The query of docs/api/turnout/GET-elections-{election}-turnout.md: `bucket`. */
class TurnoutRequest extends FormRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'bucket' => ['sometimes', 'string', 'in:hour,day'],
        ];
    }

    /**
     * `hour`, `day`, or null for the default of the election's length.
     *
     * @return 'hour'|'day'|null
     */
    public function bucket(): ?string
    {
        $bucket = $this->input('bucket');

        return match ($bucket) {
            'hour' => 'hour',
            'day' => 'day',
            default => null,
        };
    }
}
