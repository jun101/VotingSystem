<?php

namespace App\Actions\Credentials;

use App\Exceptions\ApiException;
use App\Models\Credential;
use App\Models\Election;
use App\Models\GeneratedFile;
use App\Models\Institution;
use App\Models\User;
use App\Models\Voter;
use App\Models\VoterGroup;
use App\Support\Credentials\EmailQuota;
use App\Support\Credentials\PartSplitter;
use App\Support\Credentials\RowCount;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * The request side of a run: under the election's row lock it judges the state, counts who is
 * served and who already has a code, and creates the `queued` file rows, one per group with
 * voters to serve and per part of at most 1 000. The codes and the PDFs are written by the job.
 */
final class StartCredentialRun
{
    /** A file still `queued` after this many minutes is taken for lost when a run starts. */
    public const STALE_MINUTES = 15;

    /**
     * @param  'all'|'group'|'ungrouped'|'failed_email'  $scope
     * @param  bool  $email  the voters served who have an address are emailed their code
     * @param  int|null  $confirmEmails  the count of emails the person confirmed (needed when `$email`)
     * @param  'xlsx'|'csv'|null  $format  the self-distribution file asked for: one more file, written last
     * @return array{files: list<GeneratedFile>, voters: int, skipped: int, emails: int}
     *
     * @throws ApiException 404, 403 `email_not_verified` (with `$email`), or 409 `election_codes_locked`,
     *                      `run_in_progress`, `nothing_to_generate`, `nothing_to_email`, `email_count_changed`
     *                      (with the count now true), `email_quota_exceeded` (with the number left)
     */
    public function __invoke(Election $election, string $scope, ?VoterGroup $group, ?User $user, bool $email = false, ?int $confirmEmails = null, ?string $format = null): array
    {
        $stale = [];

        // A person whose own address is unconfirmed cannot make the platform send mail in the institution's name.
        if ($email && $user !== null && $user->email_verified_at === null) {
            throw new ApiException(403, 'email_not_verified');
        }

        $run = DB::transaction(function () use ($election, $scope, $group, $user, $email, $confirmEmails, $format, &$stale): array {
            // Locked first, as every write of the voters and codes of an election.
            $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            $locked->assertCodesOpen();

            $stale = $this->failStale($locked);

            if (GeneratedFile::query()
                ->where('election_id', $locked->id)
                ->where('status', GeneratedFile::STATUS_QUEUED)
                ->exists()) {
                throw new ApiException(409, 'run_in_progress');
            }

            $counts = $scope === 'failed_email' ? $this->failedCounts($locked) : $this->counts($locked, $scope, $group);
            $skipped = array_sum(array_column($counts, 'coded'));
            $toServe = 0;
            $emails = 0;

            foreach ($counts as $row) {
                $toServe += $row['total'] - $row['coded'];
                $emails += $row['emailable'];
            }

            if ($toServe === 0) {
                throw new ApiException(409, 'nothing_to_generate');
            }

            if ($email) {
                if ($emails === 0) {
                    throw new ApiException(409, 'nothing_to_email');
                }

                // The count the person saw is read again under the lock: a voter added, removed
                // or given an address since the preview changes it, and then nothing is done.
                if ($confirmEmails !== $emails) {
                    throw new ApiException(409, 'email_count_changed', count: $emails);
                }

                // The institution's emails of the last 24 hours, under the institution's row so two
                // runs of two elections cannot both pass (the election's row is held: that order only).
                Institution::query()->whereKey($locked->institution_id)->lockForUpdate()->first();
                $left = EmailQuota::left($locked->institution_id);

                if ($emails > $left) {
                    throw new ApiException(409, 'email_quota_exceeded', count: $left);
                }
            }

            $files = [];
            // The files of a run share one identifier: the file of codes finds the slips of its run by it.
            $runId = Uuid::uuid4()->toString();
            $now = Carbon::now('UTC');

            foreach ($this->groupsToServe($locked, $counts) as [$serving, $left]) {
                $sizes = PartSplitter::sizes($left);

                foreach ($sizes as $index => $size) {
                    $file = new GeneratedFile;
                    $file->run = $runId;
                    $file->institution_id = $locked->institution_id;
                    $file->election_id = $locked->id;
                    $file->user_id = $user?->id;
                    $file->kind = GeneratedFile::KIND_SLIPS;
                    $file->voter_group_id = $serving?->id;
                    $file->part = $index + 1;
                    $file->parts = count($sizes);
                    $file->voter_count = $size;
                    $file->status = GeneratedFile::STATUS_QUEUED;
                    $file->failure = null;
                    $file->send_email = $email;
                    $file->replace = $scope === 'failed_email';
                    $file->expires_at = $locked->ends_at;
                    $file->created_at = $now;
                    $file->updated_at = $now;
                    $file->save();
                    $file->setRelation('group', $serving);
                    $file->setRelation('election', $locked);

                    $files[] = $file;
                }
            }

            if ($format !== null) {
                // The codes are made inside the jobs of the slip files and never stored: this file is
                // assembled from what they hand over, and it is the last of the chain.
                $file = new GeneratedFile;
                $file->run = $runId;
                $file->institution_id = $locked->institution_id;
                $file->election_id = $locked->id;
                $file->user_id = $user?->id;
                $file->kind = $format === 'csv' ? GeneratedFile::KIND_CODES_CSV : GeneratedFile::KIND_CODES_XLSX;
                $file->voter_group_id = $scope === 'group' ? $group?->id : null;
                $file->part = 1;
                $file->parts = 1;
                $file->voter_count = $toServe;
                $file->status = GeneratedFile::STATUS_QUEUED;
                $file->failure = null;
                $file->send_email = false;
                $file->replace = false;
                $file->ungrouped = $scope === 'ungrouped';
                $file->expires_at = $locked->ends_at;
                $file->created_at = $now;
                $file->updated_at = $now;
                $file->save();
                $file->setRelation('group', $scope === 'group' ? $group : null);
                $file->setRelation('election', $locked);

                $files[] = $file;
            }

            return ['files' => $files, 'voters' => $toServe, 'skipped' => $skipped, 'emails' => $email ? $emails : 0];
        });

        // After the commit: the copies a lost job had claimed.
        foreach ($stale as $name) {
            try {
                Storage::disk('credentials')->delete($name);
            } catch (Throwable) {
                // An orphan under a random name at worst.
            }
        }

        return $run;
    }

    /**
     * Marks `failed` the files of the election still `queued` after 15 minutes: a lost job must
     * not block the election. The election's row is locked by the caller.
     *
     * @return list<string> the stored names to remove after the commit
     */
    private function failStale(Election $election): array
    {
        $old = GeneratedFile::query()
            ->where('election_id', $election->id)
            ->where('status', GeneratedFile::STATUS_QUEUED)
            ->where('created_at', '<', Carbon::now('UTC')->subMinutes(self::STALE_MINUTES))
            ->get(['id', 'stored_file']);

        if ($old->isEmpty()) {
            return [];
        }

        // The messages the lost files had queued will never be sent: a resend can serve them.
        Credential::withoutInstitutionScope()
            ->whereIn('email_file_id', $old->pluck('id'))
            ->where('email_status', Credential::EMAIL_QUEUED)
            ->update(['email_status' => Credential::EMAIL_FAILED, 'email_sent_at' => null, 'email_error' => Credential::ERROR_SEND_FAILED]);

        GeneratedFile::query()->whereIn('id', $old->pluck('id'))->update([
            'status' => GeneratedFile::STATUS_FAILED,
            'failure' => GeneratedFile::FAILURE_GENERATION,
            'stored_file' => null,
            'updated_at' => Carbon::now('UTC'),
        ]);

        return array_values(array_filter($old->pluck('stored_file')->all(), is_string(...)));
    }

    /**
     * Voters and voters with a code, by group id (key 0 for the voters with no group).
     *
     * @return array<int, array{total: int, coded: int, emailable: int}>
     */
    private function counts(Election $election, string $scope, ?VoterGroup $group): array
    {
        $query = Voter::query()
            ->where('voters.election_id', $election->id)
            ->leftJoin('credentials', function (JoinClause $join): void {
                $join->on('credentials.voter_id', '=', 'voters.id')
                    ->on('credentials.institution_id', '=', 'voters.institution_id');
            })
            ->selectRaw('voters.voter_group_id as group_key, count(*) as total, count(credentials.id) as coded, sum(credentials.id is null and voters.email is not null) as emailable')
            ->groupBy('voters.voter_group_id');

        if ($scope === 'group' && $group !== null) {
            $query->where('voters.voter_group_id', $group->id);
        } elseif ($scope === 'ungrouped') {
            $query->whereNull('voters.voter_group_id');
        }

        $counts = [];

        foreach ($query->get() as $row) {
            $counts[RowCount::of($row, 'group_key')] = [
                'total' => RowCount::of($row, 'total'),
                'coded' => RowCount::of($row, 'coded'),
                'emailable' => RowCount::of($row, 'emailable'),
            ];
        }

        return $counts;
    }

    /**
     * The scope `failed_email`, by group id (key 0 for no group): the voters whose last email
     * failed and who have an address. Every one of them is served and emailed; none is skipped.
     *
     * @return array<int, array{total: int, coded: int, emailable: int}>
     */
    private function failedCounts(Election $election): array
    {
        $rows = Voter::query()
            ->where('voters.election_id', $election->id)
            ->join('credentials', function (JoinClause $join): void {
                $join->on('credentials.voter_id', '=', 'voters.id')
                    ->on('credentials.institution_id', '=', 'voters.institution_id');
            })
            ->where('credentials.email_status', Credential::EMAIL_FAILED)
            ->whereNotNull('voters.email')
            ->selectRaw('voters.voter_group_id as group_key, count(*) as total')
            ->groupBy('voters.voter_group_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $total = RowCount::of($row, 'total');
            $counts[RowCount::of($row, 'group_key')] = ['total' => $total, 'coded' => 0, 'emailable' => $total];
        }

        return $counts;
    }

    /**
     * The groups that have voters to serve, by name, the voters with no group last.
     *
     * @param  array<int, array{total: int, coded: int, emailable: int}>  $counts
     * @return list<array{0: VoterGroup|null, 1: int}>
     */
    private function groupsToServe(Election $election, array $counts): array
    {
        $plan = [];
        $ids = array_filter(array_keys($counts), fn (int $key): bool => $key !== 0);

        if ($ids !== []) {
            $groups = VoterGroup::query()
                ->where('election_id', $election->id)
                ->whereIn('id', $ids)
                ->orderBy('name')
                ->orderBy('id')
                ->get();

            foreach ($groups as $group) {
                $left = $counts[$group->id]['total'] - $counts[$group->id]['coded'];

                if ($left > 0) {
                    $plan[] = [$group, $left];
                }
            }
        }

        if (isset($counts[0]) && $counts[0]['total'] - $counts[0]['coded'] > 0) {
            $plan[] = [null, $counts[0]['total'] - $counts[0]['coded']];
        }

        return $plan;
    }
}
