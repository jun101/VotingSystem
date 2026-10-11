<?php

namespace App\Http\Requests\Elections;

use App\Http\Requests\Concerns\ReportsRuleCodes;
use Illuminate\Foundation\Http\FormRequest;

/** docs/api/elections/POST-elections-{election}-schedule.md: an optional `accept_warnings` boolean. */
class ScheduleElectionRequest extends FormRequest
{
    use ReportsRuleCodes;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'accept_warnings' => ['sometimes', 'boolean'],
        ];
    }

    public function acceptsWarnings(): bool
    {
        return $this->boolean('accept_warnings');
    }
}
