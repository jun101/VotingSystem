<?php

namespace App\Http\Requests\Groups;

use App\Http\Requests\Concerns\TrimsInput;
use App\Models\Election;
use App\Models\VoterGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** docs/api/groups/POST-elections-{election}-groups.md. */
class CreateGroupRequest extends FormRequest
{
    use TrimsInput;

    protected function prepareForValidation(): void
    {
        $this->trimFields(['name']);
    }

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:100'],
        ];
    }

    /**
     * The name is also unique in the election, ignoring case and surrounding spaces, accents
     * counted (the failed code is `unique`).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $name = $this->input('name');

            if (! is_string($name) || $validator->errors()->has('name')) {
                return;
            }

            $group = $this->route('group');

            $taken = VoterGroup::query()
                ->where('election_id', $this->electionKey())
                ->where('name_key', VoterGroup::keyOf($name))
                ->when($group instanceof VoterGroup, fn ($query) => $query->whereKeyNot($group instanceof VoterGroup ? $group->getKey() : null))
                ->exists();

            if ($taken) {
                $validator->errors()->add('name', 'unique');
            }
        }];
    }

    public function name(): string
    {
        $name = $this->validated('name');

        return is_string($name) ? $name : '';
    }

    protected function electionKey(): mixed
    {
        $election = $this->route('election');

        return $election instanceof Election ? $election->getKey() : null;
    }
}
