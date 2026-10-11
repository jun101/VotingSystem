<?php

namespace App\Http\Requests\Publication;

use Illuminate\Foundation\Http\FormRequest;

/**
 * docs/api/publication/POST-elections-{election}-publish.md: the optional display choice. A plain `in` rule on
 * purpose: the shared renderer answers it as `results_display: invalid`.
 */
class PublishElectionRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'results_display' => ['nullable', 'in:full,winners'],
        ];
    }

    /** The display asked for, or null to keep the election's own. */
    public function display(): ?string
    {
        $value = $this->input('results_display');

        return is_string($value) ? $value : null;
    }
}
