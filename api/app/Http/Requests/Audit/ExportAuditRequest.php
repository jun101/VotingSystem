<?php

namespace App\Http\Requests\Audit;

use Illuminate\Contracts\Validation\ValidationRule;

/** The query of docs/api/audit/GET-audit-export.md: `format` and the filters of the list. */
class ExportAuditRequest extends AuditFilterRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return parent::rules() + [
            'format' => ['required', 'string', 'in:xlsx,csv'],
        ];
    }

    /** `xlsx` or `csv`. */
    public function fileFormat(): string
    {
        return $this->input('format') === 'csv' ? 'csv' : 'xlsx';
    }
}
