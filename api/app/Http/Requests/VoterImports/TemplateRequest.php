<?php

namespace App\Http\Requests\VoterImports;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** The query of docs/api/voter-imports/GET-elections-{election}-voter-imports-template.md. */
class TemplateRequest extends FormRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'format' => ['nullable', 'string', 'in:xlsx,csv'],
        ];
    }

    /** `xlsx` (the default) or `csv`. */
    public function templateFormat(): string
    {
        return $this->input('format') === 'csv' ? 'csv' : 'xlsx';
    }
}
