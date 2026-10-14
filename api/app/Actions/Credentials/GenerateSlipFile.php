<?php

namespace App\Actions\Credentials;

use App\Exceptions\ApiException;
use App\Jobs\SendCredentialEmail;
use App\Models\Credential;
use App\Models\Election;
use App\Models\GeneratedFile;
use App\Models\Institution;
use App\Models\Voter;
use App\Models\VoterGroup;
use App\Support\Credentials\AccessCode;
use App\Support\Credentials\Slip;
use App\Support\Credentials\SlipSheet;
use App\Support\Credentials\SlipWriter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * The job's work for one queued file, in three phases so the election's row is locked only while
 * it must be:
 *
 * 1. a short transaction, the election's row locked first: it picks the voters of the file's scope
 *    who still have no code (at most the file's count), writes their codes and claims the file by
 *    giving it the name its PDF will have. No voter left: the file's row is removed;
 * 2. outside any transaction or lock: the PDF is built from the slips in memory, encrypted and stored;
 * 3. a short transaction, the election's row locked first: the file is marked `ready`.
 *
 * A failure of phase 2 or 3 deletes the codes phase 1 wrote (the voter and the hash must both
 * match: a code reissued meanwhile is not touched; a code that replaced another, for the scope
 * `failed_email`, puts the old row back), removes the stored copy and marks the file `failed`: a
 * code that no file shows is never kept. When the run sends emails, phase 1 sets the voters'
 * `email_status` to `queued` with the codes; the messages are queued after phase 3 commits. The lock order is always election, file, codes.
 *
 * Nobody is signed in when it runs: every record is read by its own key. Nothing of a code, a
 * name or a request is logged: an outcome, a count, an exception class.
 */
final class GenerateSlipFile
{
    /** The group printed on the slips of the voters with no group. */
    public const NO_GROUP = 'Sans groupe';

    public function __construct(
        private readonly SlipWriter $writer,
        private readonly WriteCredential $credentials,
        private readonly AppendCodeRows $codeRows,
    ) {}

    public function __invoke(string $fileUuid): void
    {
        // The job has no signed-in user: the file is found by its own UUID.
        $file = GeneratedFile::withoutInstitutionScope()->where('uuid', $fileUuid)->first();

        // Not queued, or already claimed by another job: nothing to do.
        if ($file === null || $file->status !== GeneratedFile::STATUS_QUEUED || $file->stored_file !== null) {
            return;
        }

        $plan = null;

        try {
            $plan = DB::transaction(fn (): ?ClaimedFile => $this->claim($file));
        } catch (FileTaken) {
            // Another job holds it: not ours to touch.
            return;
        } catch (Throwable $e) {
            // Nothing was committed: the file is failed, no code to take back.
            $this->fail($fileUuid, $e);

            return;
        }

        if ($plan === null) {
            Log::info('credentials.file', ['outcome' => 'removed']);

            return;
        }

        try {
            $size = $this->store($plan);
            DB::transaction(fn () => $this->finish($file, $plan, $size));
        } catch (Throwable $e) {
            $this->compensate($file, $plan, $e);

            return;
        }

        // After the commit: the rows of the self-distribution file of the run, if one was asked for.
        // A failure here is found by that file's job, which fails rather than write an incomplete file.
        $this->handOverRows($file, $plan);

        // After the commit of the file: a message that was queued is sent only for a file that exists.
        $this->dispatchEmails($plan);

        Log::info('credentials.file', ['outcome' => 'ready', 'voters' => count($plan->slips), 'emails' => count($plan->emails)]);
    }

    private function handOverRows(GeneratedFile $file, ClaimedFile $plan): void
    {
        try {
            $base = rtrim(config()->string('app.url'), '/').'/vote/'.$plan->election->uuid.'#';
            $group = $plan->group->name ?? '';
            $rows = array_map(
                static fn (Slip $slip): array => [$slip->name, $group, $slip->code, $base.$slip->code],
                $plan->slips,
            );

            ($this->codeRows)($file, $rows);
        } catch (Throwable $e) {
            Log::info('credentials.file', ['outcome' => 'rows_not_kept', 'class' => $e::class]);
        }
    }

    /**
     * One queued job per message, each holding the voter's UUID, the election's UUID and the code.
     * A message that cannot be queued is `failed` at once, so no voter stays `queued` for ever.
     */
    private function dispatchEmails(ClaimedFile $plan): void
    {
        $lost = [];

        foreach ($plan->emails as $one) {
            try {
                SendCredentialEmail::dispatch($one['uuid'], $plan->election->uuid, $one['code']);
            } catch (Throwable $e) {
                $lost[] = $one;
                Log::info('credentials.email', ['outcome' => 'dispatch_failed', 'class' => $e::class]);
            }
        }

        foreach ($lost as $one) {
            try {
                // Queue job: nobody is signed in; the voter and the hash of the code are the keys.
                Credential::withoutInstitutionScope()
                    ->where('voter_id', $one['voter'])
                    ->where('code_hash', $one['hash'])
                    ->where('email_status', Credential::EMAIL_QUEUED)
                    ->update(['email_status' => Credential::EMAIL_FAILED, 'email_error' => Credential::ERROR_SEND_FAILED]);
            } catch (Throwable) {
                // The status stays `queued`: the voters list shows it and a new run can replace it.
            }
        }
    }

    /**
     * Marks a file failed if it is still queued, in a fresh short transaction (the election's row,
     * then the file's), and removes the copy it had claimed. A transaction left open by a worker
     * that is being stopped is rolled back by the job before it calls this.
     */
    public function fail(string $fileUuid, ?Throwable $reason = null): void
    {
        $stored = null;
        $marked = false;

        try {
            // The same reason as above: nobody is signed in.
            $file = GeneratedFile::withoutInstitutionScope()->where('uuid', $fileUuid)->first();

            if ($file !== null) {
                [$marked, $stored] = DB::transaction(fn (): array => $this->markFailed($file->id, $file->election_id));
            }
        } catch (Throwable $e) {
            Log::info('credentials.file', ['outcome' => 'failed', 'class' => $e::class]);

            return;
        }

        $this->removeStored($stored);

        if ($marked) {
            Log::info('credentials.file', ['outcome' => 'failed', 'class' => $reason !== null ? $reason::class : 'unknown']);
        }
    }

    /**
     * Phase 1. Runs inside the transaction. Null when no voter was left and the file is removed.
     */
    private function claim(GeneratedFile $file): ?ClaimedFile
    {
        // Locked first: no run, reissue or voter write of this election happens meanwhile.
        $election = Election::withoutInstitutionScope()->whereKey($file->election_id)->lockForUpdate()->first();

        if ($election === null) {
            throw new ApiException(404, 'not_found');
        }

        $election->assertCodesOpen();

        // Read again under the lock: another job may have taken the file.
        $current = GeneratedFile::withoutInstitutionScope()->whereKey($file->id)->lockForUpdate()->first();

        if ($current === null || $current->status !== GeneratedFile::STATUS_QUEUED || $current->stored_file !== null) {
            throw new FileTaken;
        }

        $group = null;

        if ($current->voter_group_id !== null) {
            // Queue job: nobody is signed in, so the scope reads no institution; the file's own is used.
            $group = VoterGroup::withoutInstitutionScope()->whereKey($current->voter_group_id)->first();
        }

        // Queue job: nobody is signed in, so the scope reads no institution; the election is the bound.
        $query = Voter::withoutInstitutionScope()
            ->where('voters.election_id', $election->id)
            ->when(
                $current->voter_group_id === null,
                fn ($query) => $query->whereNull('voters.voter_group_id'),
                fn ($query) => $query->where('voters.voter_group_id', $current->voter_group_id),
            );

        if ($current->replace) {
            // The scope `failed_email`: the voters whose last email failed, who still have an address.
            $query->join('credentials', 'credentials.voter_id', '=', 'voters.id')
                ->where('credentials.email_status', Credential::EMAIL_FAILED)
                ->whereNotNull('voters.email')
                // Someone who voted since the run began keeps the code they used.
                ->whereNotExists(function (Builder $voted): void {
                    $voted->select(DB::raw('1'))->from('participations')->whereColumn('participations.voter_id', 'voters.id');
                });
        } else {
            $query->whereNotExists(function (Builder $credentials): void {
                $credentials->select(DB::raw('1'))->from('credentials')->whereColumn('credentials.voter_id', 'voters.id');
            });
        }

        $voters = $query
            ->orderBy('voters.full_name')
            ->orderBy('voters.id')
            ->limit($current->voter_count)
            ->get(['voters.id', 'voters.uuid', 'voters.full_name', 'voters.email']);

        if ($voters->isEmpty()) {
            // Everyone was served meanwhile: a file with no slip is not kept.
            $current->delete();

            return null;
        }

        // The rows a replaced code had, locked (the election's row is held, so no one else writes them).
        /** @var Collection<int, Credential> $existing */
        $existing = new Collection;

        if ($current->replace) {
            // Queue job: nobody is signed in; the election and the voters are the bound.
            $existing = Credential::withoutInstitutionScope()
                ->where('election_id', $election->id)
                ->whereIn('voter_id', $voters->pluck('id')->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('voter_id');
        }

        $slips = [];
        $issued = [];
        $emails = [];

        foreach ($voters as $voter) {
            $row = $existing->get($voter->id);
            $previous = $row === null ? null : [
                'hash' => $row->code_hash,
                'issued_at' => $row->issued_at,
                'issued_by_user_id' => $row->issued_by_user_id,
                'reissue_count' => $row->reissue_count,
                'email_status' => $row->email_status,
                'email_sent_at' => $row->email_sent_at,
                'email_error' => $row->email_error,
                'email_hash' => $row->email_hash,
                'email_file_id' => $row->email_file_id,
            ];

            // A message is queued for a voter with an address; the others are left for print.
            $queue = $current->send_email && $voter->email !== null;

            // The address read now is the one the message is held to: the job refuses a changed one.
            $code = ($this->credentials)($election, $voter->id, $current->user_id, $row, $queue, $queue ? $voter->email : null, $queue ? $current->id : null);
            $hash = AccessCode::hash($code);
            $slips[] = new Slip($voter->full_name, $group->name ?? self::NO_GROUP, $code);
            $issued[] = ['voter' => $voter->id, 'hash' => $hash, 'previous' => $previous];

            if ($queue) {
                $emails[] = ['voter' => $voter->id, 'uuid' => $voter->uuid, 'hash' => $hash, 'code' => $code];
            }
        }

        // The name the PDF will be stored under: it also marks the file as taken.
        $name = Uuid::uuid4()->toString();
        $current->forceFill(['stored_file' => $name])->save();

        // The institution is not a tenant model: no scope to lift.
        $institution = Institution::query()->whereKey($election->institution_id)->value('name');

        return new ClaimedFile(
            election: $election,
            group: $group,
            slips: $slips,
            issued: $issued,
            emails: $emails,
            name: $name,
            institution: is_string($institution) ? $institution : '',
            part: $current->part,
            parts: $current->parts,
        );
    }

    /**
     * Phase 2, no transaction and no lock: the PDF, encrypted, on the disk. Throws when it cannot.
     *
     * @return int the size of the PDF in bytes
     */
    private function store(ClaimedFile $plan): int
    {
        $election = $plan->election;
        $group = $plan->group;

        $pdf = $this->writer->write(new SlipSheet(
            institutionName: $plan->institution,
            electionTitle: $election->title,
            votingAddress: rtrim(config()->string('app.url'), '/').'/vote/'.$election->uuid,
            startsAt: $election->starts_at,
            endsAt: $election->ends_at,
            timezone: $election->timezone,
            language: $election->language,
            groupName: $group?->name,
            part: $plan->part,
            parts: $plan->parts,
            slips: $plan->slips,
        ));

        Storage::disk('credentials')->put($plan->name, Crypt::encryptString($pdf));

        return strlen($pdf);
    }

    /**
     * Phase 3. Runs inside the transaction: the file is `ready`, with its size and the voters served.
     */
    private function finish(GeneratedFile $file, ClaimedFile $plan, int $size): void
    {
        // No user is signed in: the election is locked by its own key, first.
        $election = Election::withoutInstitutionScope()->whereKey($file->election_id)->lockForUpdate()->first();

        if ($election === null) {
            throw new ApiException(404, 'not_found');
        }

        $election->assertCodesOpen();

        // No user is signed in: the file is read and locked by its own key.
        $current = GeneratedFile::withoutInstitutionScope()->whereKey($file->id)->lockForUpdate()->first();

        // Failed meanwhile (a run that started later took it for lost) or removed: the codes go back.
        if ($current === null
            || $current->status !== GeneratedFile::STATUS_QUEUED
            || $current->stored_file !== $plan->name) {
            throw new RuntimeException('The file is no longer ours.');
        }

        $current->forceFill([
            'status' => GeneratedFile::STATUS_READY,
            'failure' => null,
            'size_bytes' => $size,
            'voter_count' => count($plan->slips),
        ])->save();
    }

    /**
     * After a failure of phase 2 or 3: in one short transaction, election first, then file, then
     * codes, deletes the codes phase 1 wrote (voter and hash both match) and marks the file failed;
     * then removes the stored copy.
     */
    private function compensate(GeneratedFile $file, ClaimedFile $plan, Throwable $reason): void
    {
        try {
            DB::transaction(function () use ($file, $plan): void {
                // No user is signed in: the election is locked by its own key, first.
                $election = Election::withoutInstitutionScope()->whereKey($file->election_id)->lockForUpdate()->first();

                if ($election !== null) {
                    // No user is signed in: the file is read and locked by its own key.
                    $current = GeneratedFile::withoutInstitutionScope()->whereKey($file->id)->lockForUpdate()->first();

                    foreach ($plan->issued as $one) {
                        // No user is signed in: the codes are named by the election, the voter and the hash.
                        $written = Credential::withoutInstitutionScope()
                            ->where('election_id', $election->id)
                            ->where('voter_id', $one['voter'])
                            ->where('code_hash', $one['hash']);

                        if ($one['previous'] === null) {
                            $written->delete();

                            continue;
                        }

                        // A replaced code comes back with its statuses: nobody was emailed.
                        $written->update([
                            'code_hash' => $one['previous']['hash'],
                            'issued_at' => $one['previous']['issued_at'],
                            'issued_by_user_id' => $one['previous']['issued_by_user_id'],
                            'reissue_count' => $one['previous']['reissue_count'],
                            'email_status' => $one['previous']['email_status'],
                            'email_sent_at' => $one['previous']['email_sent_at'],
                            'email_error' => $one['previous']['email_error'],
                            'email_hash' => $one['previous']['email_hash'],
                            'email_file_id' => $one['previous']['email_file_id'],
                        ]);
                    }

                    if ($current !== null && $current->status === GeneratedFile::STATUS_QUEUED) {
                        $current->forceFill([
                            'status' => GeneratedFile::STATUS_FAILED,
                            'failure' => GeneratedFile::FAILURE_GENERATION,
                            'stored_file' => null,
                        ])->save();
                    }
                }
            });
        } catch (Throwable $e) {
            // The codes stay and the file is failed by the next run's 15-minute rule: logged by class.
            Log::info('credentials.file', ['outcome' => 'compensation_failed', 'class' => $e::class]);
        }

        $this->removeStored($plan->name);

        Log::info('credentials.file', ['outcome' => 'failed', 'class' => $reason::class]);
    }

    /**
     * Runs inside the transaction.
     *
     * @return array{0: bool, 1: string|null} marked, and the claimed copy to remove
     */
    private function markFailed(int $fileKey, int $electionKey): array
    {
        // Election first, then file: the order of every writer.
        Election::withoutInstitutionScope()->whereKey($electionKey)->lockForUpdate()->first();

        // No user is signed in: the file is read and locked by its own key.
        $file = GeneratedFile::withoutInstitutionScope()->whereKey($fileKey)->lockForUpdate()->first();

        if ($file === null || $file->status !== GeneratedFile::STATUS_QUEUED) {
            return [false, null];
        }

        $stored = $file->stored_file;

        $file->forceFill([
            'status' => GeneratedFile::STATUS_FAILED,
            'failure' => GeneratedFile::FAILURE_GENERATION,
            'stored_file' => null,
            'updated_at' => Carbon::now('UTC'),
        ])->save();

        // The codes this file wrote stay (a slip file that was lost is rerun with a new send), and the
        // messages it queued will not be sent: they are `failed`, so `failed_email` can serve them.
        Credential::withoutInstitutionScope()
            ->where('email_file_id', $file->id)
            ->where('email_status', Credential::EMAIL_QUEUED)
            ->update(['email_status' => Credential::EMAIL_FAILED, 'email_sent_at' => null, 'email_error' => Credential::ERROR_SEND_FAILED]);

        return [true, $stored];
    }

    private function removeStored(?string $name): void
    {
        if ($name === null) {
            return;
        }

        try {
            Storage::disk('credentials')->delete($name);
        } catch (Throwable) {
            // Nothing refers to it: an orphan under a random name at worst.
        }
    }
}
