<?php

namespace App\Http\Requests\Parties;

use App\Http\Requests\Concerns\TrimsInput;
use App\Models\Election;
use App\Models\Party;
use App\Rules\HexColour;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** docs/api/parties/POST-elections-{election}-parties.md. */
class CreatePartyRequest extends FormRequest
{
    use TrimsInput;

    protected function prepareForValidation(): void
    {
        $this->trimFields(['name', 'acronym', 'colour'], ['acronym']);
    }

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:100'],
            'acronym' => ['nullable', 'string', 'max:15'],
            'colour' => ['bail', 'required', new HexColour],
        ];
    }

    /**
     * The name is also unique in the election, ignoring case (the failed code is `unique`).
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

            $party = $this->route('party');

            $taken = Party::query()
                ->where('election_id', $this->electionKey())
                ->where('name_key', mb_strtolower($name))
                ->when($party instanceof Party, fn ($query) => $query->whereKeyNot($party instanceof Party ? $party->getKey() : null))
                ->exists();

            if ($taken) {
                $validator->errors()->add('name', 'unique');
            }
        }];
    }

    /**
     * The fields to write: the ones the request carries and the model accepts.
     *
     * @return array<string, mixed>
     */
    public function attributesToWrite(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return $validated;
    }

    protected function electionKey(): mixed
    {
        $election = $this->route('election');

        return $election instanceof Election ? $election->getKey() : null;
    }
}
