<?php

namespace App\Http\Requests\Elections;

use App\Http\Requests\Concerns\ReportsRuleCodes;
use App\Http\Requests\Concerns\TrimsInput;
use Illuminate\Foundation\Http\FormRequest;

/** docs/api/elections/POST-elections-{election}-duplicate.md: an optional title, and whether to copy the candidates. */
class DuplicateElectionRequest extends FormRequest
{
    use ReportsRuleCodes;
    use TrimsInput;

    protected function prepareForValidation(): void
    {
        $this->trimFields(['title']);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'bail', 'required', 'string', 'max:200'],
            'copy_candidates' => ['sometimes', 'boolean'],
        ];
    }

    public function givenTitle(): ?string
    {
        $title = $this->validated('title');

        return is_string($title) ? $title : null;
    }

    public function copyCandidates(): bool
    {
        return $this->boolean('copy_candidates');
    }
}
