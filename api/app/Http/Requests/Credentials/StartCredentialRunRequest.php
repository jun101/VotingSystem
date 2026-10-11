<?php

namespace App\Http\Requests\Credentials;

use App\Models\Election;
use App\Models\VoterGroup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** docs/api/credentials/POST-elections-{election}-credential-runs.md. */
class StartCredentialRunRequest extends FormRequest
{
    private ?VoterGroup $group = null;

    /** @return array<string, never> */
    public function rules(): array
    {
        return [];
    }

    /**
     * `scope`: `required` when missing, `invalid` when not one of the three. `group`: `required`
     * for the scope `group`, `invalid` for a value that is not a UUID of a group of this election
     * (unknown, another election's and another institution's are one answer).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $errors = $validator->errors();
            $scope = $this->input('scope');

            if ($scope === null || $scope === '') {
                $errors->add('scope', 'required');

                return;
            }

            if (! is_string($scope) || ! in_array($scope, ['all', 'group', 'ungrouped'], true)) {
                $errors->add('scope', 'invalid');

                return;
            }

            if ($scope !== 'group') {
                return;
            }

            $group = $this->input('group');

            if ($group === null || $group === '') {
                $errors->add('group', 'required');

                return;
            }

            $election = $this->route('election');

            if (! is_string($group) || ! $election instanceof Election) {
                $errors->add('group', 'invalid');

                return;
            }

            // The institution's scope hides another institution's groups.
            $this->group = VoterGroup::query()
                ->where('election_id', $election->getKey())
                ->where('uuid', strtolower($group))
                ->first();

            if ($this->group === null) {
                $errors->add('group', 'invalid');
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
