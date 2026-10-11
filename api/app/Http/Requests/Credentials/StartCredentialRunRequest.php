<?php

namespace App\Http\Requests\Credentials;

use App\Models\Election;
use App\Models\VoterGroup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/** docs/api/credentials/POST-elections-{election}-credential-runs.md. */
class StartCredentialRunRequest extends FormRequest
{
    private ?VoterGroup $group = null;

    /**
     * `scope` is required and one of three; `group` is a UUID, required for the scope `group` and
     * ignored for the other two. The codes reported are those of the endpoint file (`required`,
     * `invalid`), see `failedValidation`.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'scope' => ['bail', 'required', 'string', 'in:all,group,ungrouped'],
            'group' => ['bail', 'exclude_unless:scope,group', 'required_if:scope,group', 'string', 'uuid'],
        ];
    }

    /**
     * The codes of the endpoint file: a missing value is `required`, any other refusal `invalid`
     * (a group that is not a UUID of this election's groups included, see `after`).
     */
    protected function failedValidation(Validator $validator): never
    {
        $codes = [];
        /** @var array<string, array<string, mixed>> $failed */
        $failed = $validator->failed();

        foreach ($failed as $field => $rules) {
            $codes[$field][] = isset($rules['Required']) || isset($rules['RequiredIf']) ? 'required' : 'invalid';
        }

        // Errors added by the `after` check carry their code as the message.
        foreach ($validator->errors()->messages() as $field => $messages) {
            if (! isset($codes[$field])) {
                $codes[$field] = array_values($messages);
            }
        }

        throw ValidationException::withMessages($codes);
    }

    /**
     * The group of the scope `group` must be one of this election's (unknown, another election's
     * and another institution's are one answer: `invalid`).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->input('scope') !== 'group') {
                return;
            }

            $group = $this->input('group');
            $election = $this->route('election');

            if (! is_string($group) || ! $election instanceof Election) {
                $validator->errors()->add('group', 'invalid');

                return;
            }

            // The institution's scope hides another institution's groups.
            $this->group = VoterGroup::query()
                ->where('election_id', $election->getKey())
                ->where('uuid', strtolower($group))
                ->first();

            if ($this->group === null) {
                $validator->errors()->add('group', 'invalid');
            }
        }];
    }

    /** @return 'all'|'group'|'ungrouped' */
    public function scope(): string
    {
        $scope = $this->input('scope');

        return match ($scope) {
            'group' => 'group',
            'ungrouped' => 'ungrouped',
            default => 'all',
        };
    }

    /** The group of the scope `group`, found by the validation. */
    public function group(): ?VoterGroup
    {
        return $this->group;
    }
}
