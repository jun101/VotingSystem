<?php

namespace App\Http\Requests\Users;

use App\Http\Requests\Concerns\ReportsRuleCodes;
use Illuminate\Foundation\Http\FormRequest;

/** `?page=` and `?per_page=` of docs/api/README.md section 3 (25 by default, 100 at most). */
class ListRequest extends FormRequest
{
    use ReportsRuleCodes;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'page' => ['bail', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['bail', 'integer', 'between:1,100'],
        ];
    }

    public function page(): int
    {
        return $this->integer('page', 1);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 25);
    }
}
