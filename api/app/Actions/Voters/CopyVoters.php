<?php

namespace App\Actions\Voters;

use App\Exceptions\ApiException;
use App\Models\Election;
use App\Models\Voter;
use App\Models\VoterGroup;
use App\Support\Imports\Fold;
use App\Support\Voters\GroupByName;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Loads the voters of another election of the institution into a target election, with their
 * groups: one transaction that locks the target's row first, the voters written through the
 * model in chunks of 500. Both limits are judged before anything is written, so a 409 leaves
 * the target as it was. Credentials, codes and dates are never read.
 */
final class CopyVoters
{
    /** The voters of one chunk. */
    public const CHUNK = 500;

    public function __construct(private readonly GroupByName $groups) {}

    /**
     * @param  list<string>|null  $groupUuids  source groups to copy; null for every voter of the source
     * @param  array<string, string>  $renames  source group UUID to its new name
     * @return array{copied: int, skipped: int, groups_created: int}
     *
     * @throws ApiException 404, or 409 `election_voters_locked`, `voter_limit_reached`, `group_limit_reached`
     */
    public function __invoke(Election $target, Election $source, ?array $groupUuids, bool $includeUngrouped, array $renames): array
    {
        $target->assertVotersDeletable();

        return DB::transaction(function () use ($target, $source, $groupUuids, $includeUngrouped, $renames): array {
            // Locked first, as every write of the voters of an election.
            $locked = Election::query()->whereKey($target->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            $locked->assertVotersDeletable();

            [$identifiers, $emails, $existing] = $this->existing($locked);

            // The target's groups by comparison key.
            $known = [];

            foreach (VoterGroup::query()->where('election_id', $locked->getKey())->get(['name_key']) as $group) {
                $known[$group->name_key] = true;
            }

            $plan = $this->plan($source, $groupUuids, $includeUngrouped, $renames, $identifiers, $emails);

            if ($existing + count($plan['voters']) > Voter::LIMIT) {
                throw new ApiException(409, 'voter_limit_reached');
            }

            $toCreate = array_diff_key($plan['needed'], $known);

            if (count($known) + count($toCreate) > GroupByName::LIMIT) {
                throw new ApiException(409, 'group_limit_reached');
            }

            return $this->write($locked, $plan['voters'], $known, $plan['skipped']);
        });
    }

    /**
     * The identifiers and emails the target has, by comparison key, and its voter count.
     *
     * @return array{0: array<string, true>, 1: array<string, true>, 2: int}
     */
    private function existing(Election $locked): array
    {
        $identifiers = [];
        $emails = [];
        $count = 0;

        // The institution's scope applies: the target is the caller's own election.
        foreach (Voter::query()->where('election_id', $locked->getKey())->get(['identifier', 'email']) as $voter) {
            $count++;

            if ($voter->identifier !== null) {
                $identifiers[Fold::key($voter->identifier)] = true;
            }

            if ($voter->email !== null) {
                $emails[Fold::key($voter->email)] = true;
            }
        }

        return [$identifiers, $emails, $count];
    }

    /**
     * The voters to write, in the source's order, and how many are skipped.
     *
     * @param  list<string>|null  $groupUuids
     * @param  array<string, string>  $renames
     * @param  array<string, true>  $identifiers
     * @param  array<string, true>  $emails
     * @return array{voters: list<array{full_name: string, identifier: string|null, email: string|null, phone: string|null, group: string|null}>, skipped: int, needed: array<string, string>}
     */
    private function plan(Election $source, ?array $groupUuids, bool $includeUngrouped, array $renames, array $identifiers, array $emails): array
    {
        // The source's groups, by key: the institution's scope hides another institution's.
        $names = [];

        foreach (VoterGroup::query()->where('election_id', $source->getKey())->get(['id', 'uuid', 'name']) as $group) {
            if ($groupUuids === null || in_array($group->uuid, $groupUuids, true)) {
                $names[$group->id] = $renames[$group->uuid] ?? $group->name;
            }
        }

        $query = Voter::query()->where('election_id', $source->getKey())->orderBy('id');

        $query->where(function ($any) use ($names, $includeUngrouped): void {
            if ($names !== []) {
                $any->whereIn('voter_group_id', array_keys($names));
            }

            if ($includeUngrouped) {
                $any->orWhereNull('voter_group_id');
            }
        });

        // Nothing selected: no voter (a bare `where` with no clause would keep everyone).
        if ($names === [] && ! $includeUngrouped) {
            return ['voters' => [], 'skipped' => 0, 'needed' => []];
        }

        $voters = [];
        $needed = [];
        $skipped = 0;

        foreach ($query->get(['full_name', 'identifier', 'email', 'phone', 'voter_group_id']) as $voter) {
            $identifierKey = $voter->identifier === null ? null : Fold::key($voter->identifier);
            $emailKey = $voter->email === null ? null : Fold::key($voter->email);

            if (($identifierKey !== null && isset($identifiers[$identifierKey])) || ($emailKey !== null && isset($emails[$emailKey]))) {
                $skipped++;

                continue;
            }

            if ($identifierKey !== null) {
                $identifiers[$identifierKey] = true;
            }

            if ($emailKey !== null) {
                $emails[$emailKey] = true;
            }

            $group = $voter->voter_group_id === null ? null : ($names[$voter->voter_group_id] ?? null);

            if ($group !== null) {
                $needed[VoterGroup::keyOf($group)] = $group;
            }

            $voters[] = [
                'full_name' => $voter->full_name,
                'identifier' => $voter->identifier,
                'email' => $voter->email,
                'phone' => $voter->phone,
                'group' => $group,
            ];
        }

        return ['voters' => $voters, 'skipped' => $skipped, 'needed' => $needed];
    }

    /**
     * @param  list<array{full_name: string, identifier: string|null, email: string|null, phone: string|null, group: string|null}>  $voters
     * @param  array<string, true>  $known  the target's group keys
     * @return array{copied: int, skipped: int, groups_created: int}
     */
    private function write(Election $locked, array $voters, array $known, int $skipped): array
    {
        $copied = 0;
        $created = 0;

        foreach (array_chunk($voters, self::CHUNK) as $chunk) {
            foreach ($chunk as $row) {
                try {
                    // A savepoint: a value the index refuses leaves nothing of the voter, not even its group.
                    DB::transaction(function () use ($locked, $row, &$known, &$created): void {
                        $group = ($this->groups)($locked, $row['group']);

                        $voter = new Voter([
                            'full_name' => $row['full_name'],
                            'identifier' => $row['identifier'],
                            'email' => $row['email'],
                            'phone' => $row['phone'],
                        ]);
                        $voter->election_id = $locked->id;
                        $voter->institution_id = $locked->institution_id;
                        $voter->voter_group_id = $group?->id;
                        $voter->save();

                        if ($group !== null && ! isset($known[$group->name_key])) {
                            $known[$group->name_key] = true;
                            $created++;
                        }
                    });
                    $copied++;
                } catch (UniqueConstraintViolationException) {
                    // The index saw what the comparison keys did not (the table's own collation).
                    $skipped++;
                }
            }
        }

        return ['copied' => $copied, 'skipped' => $skipped, 'groups_created' => $created];
    }
}
