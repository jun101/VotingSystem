<?php

namespace App\Http\Requests\Audit;

use Illuminate\Contracts\Validation\ValidationRule;

/** The query of docs/api/audit/GET-audit.md: the filters, `page` and `per_page` (50 by default, 100 at most). */
class ListAuditRequest extends AuditFilterRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return parent::rules() + [
            'page' => ['bail', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['bail', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function page(): int
    {
        return $this->integer('page', 1);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 50);
    }
}
