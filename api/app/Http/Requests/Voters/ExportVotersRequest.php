<?php

namespace App\Http\Requests\Voters;

use App\Http\Requests\Concerns\TrimsInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/** The query of docs/api/turnout/GET-elections-{election}-voters-export.md. */
class ExportVotersRequest extends FormRequest
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
            'format' => ['required', 'string', 'in:xlsx,csv'],
            'voted' => ['sometimes', 'string', 'in:no,yes,all'],
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

    /** `xlsx` or `csv`. */
    public function fileFormat(): string
    {
        return $this->input('format') === 'csv' ? 'csv' : 'xlsx';
    }

    /** `no` (default), `yes` or `all`. */
    public function voted(): string
    {
        $value = $this->input('voted');

        return $value === 'yes' || $value === 'all' ? $value : 'no';
    }

    public function search(): ?string
    {
        $q = $this->input('q');

        return is_string($q) && $q !== '' ? $q : null;
    }

    /** `none`, a lower-cased group UUID, or null. */
    public function group(): ?string
    {
        $group = $this->input('group');

        return is_string($group) && $group !== '' ? strtolower($group) : null;
    }
}
