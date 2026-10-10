<?php

namespace App\Http\Resources;

use App\Enums\BallotScope;
use App\Models\Ballot;
use App\Models\Voter;
use App\Models\VoterGroup;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;

/**
 * A ballot as docs/api/ballots/GET-elections-{election}-ballots.md shows it. No election, no
 * institution, no numeric key; `result_note` is for the results slices.
 *
 * @property Ballot $resource
 */
final class BallotResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        $ballot = $this->resource;
        // The list loads them all at once; a ballot answered alone reads its own.
        $candidates = $ballot->loadMissing('candidates.party')->candidates;

        // Counted in the list's own query; a ballot answered alone counts here.
        if (! $ballot->relationLoaded('groups')) {
            $ballot->load(['groups' => static fn (BelongsToMany $query) => $query->withCount('voters')]);
        }

        $groups = $ballot->groups
            ->sortBy([['name_key', 'asc'], ['id', 'asc']]);
        $isGroup = $ballot->scope === BallotScope::Group;
        $eligible = $isGroup
            ? $groups->sum(fn (VoterGroup $group): int => $group->votersCounted())
            : $this->electionVoters($ballot);
        // A group ballot only; a general ballot with no voter has nothing to warn about here.
        $warnings = match (true) {
            ! $isGroup => [],
            $eligible === 0 => ['no_voters'],
            $eligible < 5 => ['few_voters'],
            default => [],
        };

        return [
            'title' => $ballot->title,
            'description' => $ballot->description,
            'position' => $ballot->position,
            'seats' => $ballot->seats,
            'allow_blank' => $ballot->allow_blank,
            'scope' => $ballot->scope->value,
            'groups' => $isGroup
                ? $groups->map(fn (VoterGroup $group): BallotGroupResource => new BallotGroupResource($group))->values()->all()
                : [],
            'eligible_voters' => $eligible,
            'warnings' => $warnings,
            'candidates_count' => $candidates->count(),
            'candidates' => $candidates->map(fn ($candidate): CandidateResource => new CandidateResource($candidate))->all(),
            'created_at' => $ballot->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $ballot->updated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function electionVoters(Ballot $ballot): int
    {
        $counted = $ballot->getAttribute('election_voters_count');

        return is_numeric($counted)
            ? (int) $counted
            : Voter::query()->where('election_id', $ballot->election_id)->count();
    }
}
