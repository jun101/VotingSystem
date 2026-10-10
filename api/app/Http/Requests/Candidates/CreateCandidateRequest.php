<?php

namespace App\Http\Requests\Candidates;

use App\Http\Requests\Concerns\ReportsRuleCodes;
use App\Http\Requests\Concerns\TrimsInput;
use App\Models\Ballot;
use App\Models\Party;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/** docs/api/candidates/POST-ballots-{ballot}-candidates.md. */
class CreateCandidateRequest extends FormRequest
{
    use ReportsRuleCodes;
    use TrimsInput;

    private ?Party $party = null;

    private ?Ballot $ballot = null;

    protected function prepareForValidation(): void
    {
        $this->trimFields(['first_name', 'last_name', 'slogan', 'biography'], ['slogan', 'biography']);
    }

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'first_name' => ['bail', 'required', 'string', 'max:80'],
            'last_name' => ['bail', 'required', 'string', 'max:80'],
            'sex' => ['bail', 'required', 'in:male,female'],
            'party' => ['bail', 'nullable', 'uuid'],
            'slogan' => ['nullable', 'string', 'max:80'],
            'biography' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * A party or a ballot named by UUID must be one of the candidate's own election. Unknown,
     * another election's and another institution's give the same 422.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $election = $this->electionKey();
            $party = $this->input('party');

            if (is_string($party)) {
                $this->party = Party::query()->where('election_id', $election)->where('uuid', strtolower($party))->first()
                    ?? throw ValidationException::withMessages(['party' => ['invalid']]);
            }

            $ballot = $this->input('ballot');

            if ($this->wantsBallot() && $this->has('ballot')) {
                $this->ballot = is_string($ballot)
                    ? Ballot::query()->where('election_id', $election)->where('uuid', strtolower($ballot))->first()
                    : null;

                if ($this->ballot === null) {
                    throw ValidationException::withMessages(['ballot' => ['invalid']]);
                }
            }
        }];
    }

    /**
     * The text fields to write: the ones the request carries and the model accepts.
     *
     * @return array<string, mixed>
     */
    public function attributesToWrite(): array
    {
        /** @var array<string, mixed> $written */
        $written = $this->safe()->only(['first_name', 'last_name', 'sex', 'slogan', 'biography']);

        return $written;
    }

    /** Whether the request names a party, or `null` for an independent. */
    public function namesParty(): bool
    {
        return $this->has('party');
    }

    /** The party named, in the same election; `null` for an independent. */
    public function party(): ?Party
    {
        return $this->party;
    }

    /** The other ballot of the election the candidate goes to, when the request names one. */
    public function targetBallot(): ?Ballot
    {
        return $this->ballot;
    }

    /** Only the change of a candidate can move it. */
    protected function wantsBallot(): bool
    {
        return false;
    }

    /** The election the party and the ballot must belong to. */
    protected function electionKey(): mixed
    {
        $ballot = $this->route('ballot');

        return $ballot instanceof Ballot ? $ballot->election_id : null;
    }
}
