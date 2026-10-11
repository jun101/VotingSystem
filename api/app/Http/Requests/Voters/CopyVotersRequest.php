<?php

namespace App\Http\Requests\Voters;

use App\Models\Election;
use App\Models\VoterGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** docs/api/voters/POST-elections-{election}-voter-copies.md. */
class CopyVotersRequest extends FormRequest
{
    /** The longest name of a group. */
    private const NAME_MAX = 100;

    /** Most groups one copy may name (the most groups an election holds). */
    private const MAX_GROUPS = 100;

    /** The source election, found by `after` (the institution's scope sees its own elections only). */
    private ?Election $source = null;

    /** @var list<string>|null the source groups' UUIDs asked for; null is every group */
    private ?array $groupUuids = null;

    /** @var array<string, string> source group UUID to the new name, trimmed */
    private array $renames = [];

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'source' => ['bail', 'required', 'uuid'],
            'include_ungrouped' => ['nullable', 'boolean'],
        ];
    }

    /**
     * `source`: `same` for the target itself, `invalid` for an election that is not another one of
     * the institution (one answer for unknown and another institution's). `groups` and `rename`:
     * `invalid`.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $errors = $validator->errors();
            $target = $this->route('election');
            $source = $this->input('source');

            if (is_string($source) && $target instanceof Election && ! $errors->has('source')) {
                $source = strtolower($source);

                if ($source === $target->uuid) {
                    $errors->add('source', 'same');
                } else {
                    // The institution's scope: another institution's election is not found, never told apart.
                    $this->source = Election::query()->where('uuid', $source)->first();

                    if ($this->source === null) {
                        $errors->add('source', 'invalid');
                    }
                }
            }

            $this->judgeGroups($validator);
            $this->judgeRenames($validator);
        }];
    }

    private function judgeGroups(Validator $validator): void
    {
        $groups = $this->input('groups');

        if ($groups === null) {
            return;
        }

        if (! is_array($groups) || ! array_is_list($groups)) {
            $validator->errors()->add('groups', 'invalid');

            return;
        }

        // Before any query: an election holds 100 groups at most.
        if (count($groups) > self::MAX_GROUPS) {
            $validator->errors()->add('groups', 'invalid');

            return;
        }

        $uuids = [];

        foreach ($groups as $uuid) {
            if (! is_string($uuid) || preg_match('/\A[0-9a-fA-F]{8}-([0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}\z/', $uuid) !== 1) {
                $validator->errors()->add('groups', 'invalid');

                return;
            }

            $uuids[strtolower($uuid)] = true;
        }

        $uuids = array_map('strval', array_keys($uuids));

        if ($this->source !== null && $uuids !== []) {
            // The groups of the source only: unknown, another election's and another institution's are the same answer.
            $found = VoterGroup::query()
                ->where('election_id', $this->source->getKey())
                ->whereIn('uuid', $uuids)
                ->count();

            if ($found !== count($uuids)) {
                $validator->errors()->add('groups', 'invalid');

                return;
            }
        }

        $this->groupUuids = $uuids;
    }

    private function judgeRenames(Validator $validator): void
    {
        $rename = $this->input('rename');

        if ($rename === null) {
            return;
        }

        if (! is_array($rename) || ($rename !== [] && array_is_list($rename))) {
            $validator->errors()->add('rename', 'invalid');

            return;
        }

        if ($rename === []) {
            return;
        }

        $copied = $this->groupUuids;

        if ($this->input('groups') === null) {
            // Every group is copied: any group of the source may be renamed.
            $copied = null;

            if ($this->source !== null) {
                $copied = [];

                foreach (VoterGroup::query()->where('election_id', $this->source->getKey())->get(['uuid']) as $group) {
                    $copied[] = $group->uuid;
                }
            }
        }

        foreach ($rename as $uuid => $name) {
            $name = is_string($name) ? trim($name) : null;
            $key = strtolower((string) $uuid);

            if ($name === null || $name === '' || mb_strlen($name) > self::NAME_MAX
                || ($copied !== null && ! in_array($key, $copied, true))) {
                $validator->errors()->add('rename', 'invalid');

                return;
            }

            $this->renames[$key] = $name;
        }
    }

    /** The source election, after validation. */
    public function sourceElection(): Election
    {
        return $this->source ?? throw new \LogicException('The source is read by the validation.');
    }

    /** @return list<string>|null the UUIDs of the source groups to copy; null for every group */
    public function groupUuids(): ?array
    {
        return $this->groupUuids;
    }

    /** Whether the voters with no group are copied too. */
    public function includeUngrouped(): bool
    {
        $given = $this->input('include_ungrouped');

        if ($given === null) {
            return $this->input('groups') === null;
        }

        return filter_var($given, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string, string> source group UUID to the name it takes in the target */
    public function renames(): array
    {
        return $this->renames;
    }
}
