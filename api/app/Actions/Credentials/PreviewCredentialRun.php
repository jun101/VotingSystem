<?php

namespace App\Actions\Credentials;

use App\Models\Credential;
use App\Models\Election;
use App\Models\Voter;
use App\Models\VoterGroup;
use App\Support\Credentials\RowCount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * What a run would do for a scope, without doing it: how many voters would get a code, how many
 * of them have an address, who has none (listed, 100 at most, left for print) and how many
 * already have a code. A reading: nothing is written, no code is made, no lock is taken.
 */
final class PreviewCredentialRun
{
    /** The voters without an address that are named. */
    public const LIST_LIMIT = 100;

    /**
     * @param  'all'|'group'|'ungrouped'|'failed_email'  $scope
     * @return array{to_generate: int, with_email: int, without_email: int, skipped: int, voters_without_email: list<array{id: string, full_name: string, group: array{id: string, name: string}|null}>}
     */
    public function __invoke(Election $election, string $scope, ?VoterGroup $group): array
    {
        if ($scope === 'failed_email') {
            return $this->failed($election);
        }

        $base = fn (): Builder => Voter::query()
            ->where('voters.election_id', $election->id)
            ->leftJoin('credentials', function (JoinClause $join): void {
                $join->on('credentials.voter_id', '=', 'voters.id')
                    ->on('credentials.institution_id', '=', 'voters.institution_id');
            })
            ->when($scope === 'group' && $group !== null, fn (Builder $query) => $query->where('voters.voter_group_id', $group?->id))
            ->when($scope === 'ungrouped', fn (Builder $query) => $query->whereNull('voters.voter_group_id'));

        $row = $base()
            ->selectRaw('count(credentials.id) as skipped, sum(credentials.id is null) as to_generate, sum(credentials.id is null and voters.email is not null) as with_email')
            ->first();

        $toGenerate = $row === null ? 0 : RowCount::of($row, 'to_generate');
        $withEmail = $row === null ? 0 : RowCount::of($row, 'with_email');

        $left = $base()
            ->whereNull('credentials.id')
            ->whereNull('voters.email')
            ->with('group')
            ->orderBy('voters.full_name')
            ->orderBy('voters.id')
            ->limit(self::LIST_LIMIT)
            ->get(['voters.id', 'voters.uuid', 'voters.full_name', 'voters.voter_group_id']);

        return [
            'to_generate' => $toGenerate,
            'with_email' => $withEmail,
            'without_email' => $toGenerate - $withEmail,
            'skipped' => $row === null ? 0 : RowCount::of($row, 'skipped'),
            'voters_without_email' => array_values($left->map(fn (Voter $voter): array => [
                'id' => $voter->uuid,
                'full_name' => $voter->full_name,
                'group' => $voter->group === null ? null : ['id' => $voter->group->uuid, 'name' => $voter->group->name],
            ])->all()),
        ];
    }

    /**
     * The scope `failed_email`: the voters whose last email failed and who have an address.
     *
     * @return array{to_generate: int, with_email: int, without_email: int, skipped: int, voters_without_email: list<array{id: string, full_name: string, group: array{id: string, name: string}|null}>}
     */
    private function failed(Election $election): array
    {
        $count = Voter::query()
            ->where('voters.election_id', $election->id)
            ->join('credentials', function (JoinClause $join): void {
                $join->on('credentials.voter_id', '=', 'voters.id')
                    ->on('credentials.institution_id', '=', 'voters.institution_id');
            })
            ->where('credentials.email_status', Credential::EMAIL_FAILED)
            ->whereNotNull('voters.email')
            ->count();

        return ['to_generate' => $count, 'with_email' => $count, 'without_email' => 0, 'skipped' => 0, 'voters_without_email' => []];
    }
}
