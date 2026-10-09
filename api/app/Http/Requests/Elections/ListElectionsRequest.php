<?php

namespace App\Http\Requests\Elections;

use App\Enums\ElectionStatus;
use App\Http\Requests\Users\ListRequest;
use App\Rules\Choice;
use Illuminate\Contracts\Validation\ValidationRule;

/** docs/api/elections/GET-elections.md: `status`, `year`, `page`, `per_page`. */
class ListElectionsRequest extends ListRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return parent::rules() + [
            'status' => ['sometimes', 'bail', new Choice(ElectionStatus::values())],
            'year' => ['sometimes', 'bail', 'regex:/^\d{4}$/'],
        ];
    }

    public function status(): ?ElectionStatus
    {
        $status = $this->query('status');

        return is_string($status) ? ElectionStatus::tryFrom($status) : null;
    }

    public function year(): ?int
    {
        $year = $this->query('year');

        return is_string($year) ? (int) $year : null;
    }
}
