<?php

namespace App\Http\Requests\Institution;

use App\Http\Requests\Concerns\ReportsRuleCodes;
use App\Http\Requests\Concerns\TrimsInput;
use App\Rules\Choice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** docs/api/institution/PATCH-institution.md: every field is optional, only those sent change. */
class UpdateInstitutionRequest extends FormRequest
{
    use ReportsRuleCodes;
    use TrimsInput;

    protected function prepareForValidation(): void
    {
        $this->trimFields(
            ['name', 'description', 'address', 'city', 'phone', 'contact_email', 'timezone'],
            ['description', 'address', 'city', 'phone', 'contact_email'],
        );
    }

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'bail', 'required', 'string', 'max:150'],
            'type' => ['sometimes', 'bail', new Choice(['school', 'university', 'association', 'other'])],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9 +().\-]*$/'],
            'contact_email' => ['sometimes', 'nullable', 'string', 'max:255', 'email'],
            'timezone' => ['sometimes', 'bail', 'required', 'string', 'timezone'],
            'language' => ['sometimes', 'bail', new Choice(['fr', 'en'])],
        ];
    }
}
