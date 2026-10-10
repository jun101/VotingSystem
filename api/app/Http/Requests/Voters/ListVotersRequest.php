<?php

namespace App\Http\Requests\Voters;

use App\Http\Requests\Concerns\TrimsInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/** The query of docs/api/voters/GET-elections-{election}-voters.md. */
class ListVotersRequest extends FormRequest
{
    use TrimsInput;

    protected function prepareForValidation(): void
    {
        $this->trimFields(['q', 'group'], ['q', 'group']);
    }

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'page' => ['bail', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['bail', 'integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'group' => ['nullable', 'string'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $group = $this->input('group');

            if (is_string($group) && $group !== 'none' && ! Str::isUuid($group) && ! $validator->errors()->has('group')) {
                $validator->errors()->add('group', 'invalid');
            }
        }];
    }

    public function page(): int
    {
        return $this->integer('page', 1);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 24);
    }

    /** The search text, or null when blank. */
    public function search(): ?string
    {
        $q = $this->input('q');

        return is_string($q) && $q !== '' ? $q : null;
    }

    /** `none`, a lower-cased group UUID, or null for no filter. */
    public function group(): ?string
    {
        $group = $this->input('group');

        return is_string($group) && $group !== '' ? strtolower($group) : null;
    }
}
