<?php

namespace App\Http\Requests\VoterImports;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** The query of docs/api/voter-imports/GET-voter-imports-{import}-rows.md. */
class ListImportRowsRequest extends FormRequest
{
    /** Rows on a page. */
    public const PER_PAGE = 50;

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'filter' => ['bail', 'string', 'in:all,ok,errors'],
            'page' => ['bail', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function filter(): string
    {
        $filter = $this->input('filter');

        return is_string($filter) ? $filter : 'all';
    }

    public function page(): int
    {
        return $this->integer('page', 1);
    }
}
