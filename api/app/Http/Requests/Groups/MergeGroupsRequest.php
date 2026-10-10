<?php

namespace App\Http\Requests\Groups;

use App\Models\VoterGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** docs/api/groups/POST-groups-{group}-merge.md. */
class MergeGroupsRequest extends FormRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'into' => ['bail', 'required', 'uuid'],
        ];
    }

    /**
     * `same` for the group itself; `invalid` for a group that is not another group of the same
     * election, with one answer for an unknown, another election's and another institution's.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $into = $this->input('into');
            $group = $this->route('group');

            if (! is_string($into) || ! $group instanceof VoterGroup || $validator->errors()->has('into')) {
                return;
            }

            $into = strtolower($into);

            if ($into === $group->uuid) {
                $validator->errors()->add('into', 'same');

                return;
            }

            $exists = VoterGroup::query()
                ->where('uuid', $into)
                ->where('election_id', $group->election_id)
                ->exists();

            if (! $exists) {
                $validator->errors()->add('into', 'invalid');
            }
        }];
    }

    /** The receiving group's UUID, lower-cased. */
    public function into(): string
    {
        $into = $this->validated('into');

        return strtolower(is_string($into) ? $into : '');
    }
}
