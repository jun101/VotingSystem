<?php

namespace App\Http\Requests\Ballots;

use App\Enums\BallotScope;
use App\Http\Requests\Concerns\ReportsRuleCodes;
use App\Http\Requests\Concerns\TrimsInput;
use App\Models\Ballot;
use App\Models\Election;
use App\Models\VoterGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/** docs/api/ballots/POST-elections-{election}-ballots.md. */
class CreateBallotRequest extends FormRequest
{
    use ReportsRuleCodes {
        failedValidation as private reportRuleCodes;
    }
    use TrimsInput;

    /** Most groups one ballot may cover (the most groups an election holds). */
    private const MAX_GROUPS = 100;

    /** @var array<string, list<string>> codes added by the scope and groups checks */
    private array $scopeCodes = [];

    private ?BallotScope $scopeToWrite = null;

    /** @var list<string>|null */
    private ?array $groupsToWrite = null;

    protected function prepareForValidation(): void
    {
        $this->trimFields(['title', 'description'], ['description']);
    }

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'title' => ['bail', 'required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'seats' => ['bail', 'integer', 'min:1', 'max:20'],
            'allow_blank' => ['boolean'],
            'scope' => ['nullable', 'string'],
            'groups' => ['nullable', 'array'],
            'groups.*' => ['string'],
        ];
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

        // `scope` and `groups` are written by the controller, not mass-assigned.
        /** @var array<string, mixed> $only */
        $only = Arr::only($validated, ['title', 'description', 'seats', 'allow_blank']);

        return $only;
    }

    /**
     * The scope and the groups (docs/api/ballots/POST-elections-{election}-ballots.md scenarios 15
     * and 16): `invalid` and `required`, with one answer for an unknown, foreign or other election's
     * group. They are looked up in the election of the route (the ballot's own, on a change).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->judgeScope($validator);
        }];
    }

    /** The new scope, or null when the request leaves it alone. */
    public function scopeToWrite(): ?BallotScope
    {
        return $this->scopeToWrite;
    }

    /**
     * The UUIDs of the groups to store (an empty list clears them), or null to leave them alone.
     *
     * @return list<string>|null
     */
    public function groupsToWrite(): ?array
    {
        return $this->groupsToWrite;
    }

    private function judgeScope(Validator $validator): void
    {
        $route = $this->route('ballot');
        $ballot = $route instanceof Ballot ? $route : null;
        $election = $this->route('election');
        $electionId = $ballot !== null ? $ballot->election_id : ($election instanceof Election ? $election->getKey() : null);
        $current = $ballot !== null ? $ballot->scope : BallotScope::General;

        $effective = $current;

        if ($this->has('scope')) {
            $effective = BallotScope::tryFrom((string) (is_string($this->input('scope')) ? $this->input('scope') : ''));

            if ($effective === null) {
                $this->fail($validator, 'scope', 'invalid');

                return;
            }

            $this->scopeToWrite = $effective;
        }

        if ($effective === BallotScope::General) {
            // Ignored and cleared, as the endpoint file says.
            $this->groupsToWrite = $this->has('scope') ? [] : null;

            return;
        }

        $given = $this->input('groups');
        $absent = ! $this->has('groups') || (! $this->has('scope') && $given === []);

        if ($absent) {
            if ($current !== BallotScope::Group) {
                $this->fail($validator, 'groups', 'required');
            }

            return;
        }

        if ($given === [] || $given === null) {
            $this->fail($validator, 'groups', 'required');

            return;
        }

        if (! is_array($given) || ! array_is_list($given)) {
            $this->fail($validator, 'groups', 'invalid');

            return;
        }

        $uuids = [];

        foreach ($given as $item) {
            if (! is_string($item) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $item) !== 1) {
                $this->fail($validator, 'groups', 'invalid');

                return;
            }

            $uuids[strtolower($item)] = true;
        }

        $uuids = array_keys($uuids);

        // The tenant scope keeps another institution's groups out; the election keeps the other elections'.
        $found = $electionId === null ? 0 : VoterGroup::query()
            ->where('election_id', $electionId)
            ->whereIn('uuid', $uuids)
            ->count();

        if (count($uuids) > self::MAX_GROUPS || $found !== count($uuids)) {
            $this->fail($validator, 'groups', 'invalid');

            return;
        }

        $this->groupsToWrite = $uuids;
    }

    private function fail(Validator $validator, string $field, string $code): void
    {
        $this->scopeCodes[$field][] = $code;
        $validator->errors()->add($field, $code);
    }

    protected function failedValidation(Validator $validator): never
    {
        /** @var array<string, list<string>> $codes */
        $codes = [];

        try {
            $this->reportRuleCodes($validator);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $codes */
            $codes = $e->errors();
        }

        // One answer for whatever is wrong with the scope or the groups (a type, a UUID, a member).
        foreach (array_keys($codes) as $field) {
            if ($field === 'scope' || $field === 'groups' || str_starts_with((string) $field, 'groups.')) {
                $whole = str_starts_with((string) $field, 'groups') ? 'groups' : 'scope';
                unset($codes[$field]);
                $codes[$whole] = ['invalid'];
            }
        }

        foreach ($this->scopeCodes as $field => $list) {
            $codes[$field] = [...($codes[$field] ?? []), ...$list];
        }

        throw ValidationException::withMessages($codes);
    }
}
