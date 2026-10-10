<?php

namespace App\Http\Requests\Voters;

use App\Http\Requests\Concerns\TrimsInput;
use App\Models\Election;
use App\Models\Voter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFactory;

/** docs/api/voters/POST-elections-{election}-voters.md. */
class CreateVoterRequest extends FormRequest
{
    use TrimsInput;

    protected function prepareForValidation(): void
    {
        $this->trimFields(['full_name', 'group', 'identifier', 'email', 'phone'], ['group', 'identifier', 'email', 'phone']);
    }

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'full_name' => ['bail', 'required', 'string', 'max:150'],
            'group' => ['nullable', 'string', 'max:100'],
            'identifier' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * The format of the email and of the phone, and the identifier and the email being unique in
     * the election (the failed codes are `email`, `invalid` and `unique`).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $errors = $validator->errors();
            $email = $this->input('email');
            $phone = $this->input('phone');
            $identifier = $this->input('identifier');

            if (is_string($email) && ! $errors->has('email')) {
                if (! ValidatorFactory::make(['email' => $email], ['email' => 'email'])->passes()) {
                    $errors->add('email', 'email');
                } elseif ($this->taken('email', mb_strtolower($email))) {
                    $errors->add('email', 'unique');
                }
            }

            if (is_string($phone) && ! $errors->has('phone') && preg_match('/\A[0-9+\-(). ]*\z/', $phone) !== 1) {
                $errors->add('phone', 'invalid');
            }

            if (is_string($identifier) && ! $errors->has('identifier') && $this->taken('identifier', $identifier)) {
                $errors->add('identifier', 'unique');
            }
        }];
    }

    /**
     * The fields to write: the ones the request carries (the email lower-cased); `group` is the
     * group's name and is handled by the controller.
     *
     * @return array<string, mixed>
     */
    public function attributesToWrite(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        if (isset($validated['email']) && is_string($validated['email'])) {
            $validated['email'] = mb_strtolower($validated['email']);
        }

        unset($validated['group']);

        return $validated;
    }

    /** Whether the request names a group (possibly null, to clear it). */
    public function hasGroup(): bool
    {
        return $this->has('group');
    }

    /** The group's name, or null for no group. */
    public function groupName(): ?string
    {
        $group = $this->input('group');

        return is_string($group) && $group !== '' ? $group : null;
    }

    protected function electionKey(): mixed
    {
        $election = $this->route('election');

        return $election instanceof Election ? $election->getKey() : null;
    }

    /** The voter being changed, when there is one: its own values are not duplicates. */
    protected function ownKey(): mixed
    {
        return null;
    }

    /**
     * The columns compare case-insensitively (the table's collation); the unique indexes are the
     * backstop of this check.
     */
    private function taken(string $column, string $value): bool
    {
        return Voter::query()
            ->where('election_id', $this->electionKey())
            ->where($column, $value)
            ->when($this->ownKey() !== null, fn ($query) => $query->whereKeyNot($this->ownKey()))
            ->exists();
    }
}
