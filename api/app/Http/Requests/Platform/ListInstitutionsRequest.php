<?php

namespace App\Http\Requests\Platform;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** The query of docs/api/platform/GET-platform-institutions.md: `q`, `status`, `page` and `per_page`. */
class ListInstitutionsRequest extends FormRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'q' => ['bail', 'nullable', 'string', 'max:100'],
            'status' => ['bail', 'nullable', 'in:active,suspended'],
            'page' => ['bail', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['bail', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** The part of a name searched for, or null. */
    public function term(): ?string
    {
        $value = $this->input('q');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** `active`, `suspended` or null. */
    public function status(): ?string
    {
        $value = $this->input('status');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function page(): int
    {
        return $this->integer('page', 1);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 20);
    }
}
