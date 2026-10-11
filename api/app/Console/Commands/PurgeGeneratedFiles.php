<?php

namespace App\Console\Commands;

use App\Enums\ElectionStatus;
use App\Models\Election;
use App\Models\GeneratedFile;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Clears the slip files (FR-CRED-05: downloadable until the election closes, then deleted):
 * the rows and the stored copies of the files of closed, published and archived elections, queued
 * and failed rows included; and every file left `queued` for more than an hour is marked
 * `failed` (`generation_failed`) so a lost job cannot block the election's next run.
 *
 * It can run twice in a row: the second run finds nothing. It prints counts only, never a file
 * name, a code or a voter. Scheduled daily (routes/console.php).
 */
class PurgeGeneratedFiles extends Command
{
    /** A file still `queued` after this many minutes is taken for lost. */
    public const STUCK_MINUTES = 60;

    protected $signature = 'files:purge';

    protected $description = 'Delete the slip files of closed, published and archived elections, and fail the files stuck queued';

    public function handle(): int
    {
        $disk = Storage::disk('credentials');
        $deleted = 0;
        $failedCopies = 0;
        $stuck = Carbon::now('UTC')->subMinutes(self::STUCK_MINUTES);

        $locked = [ElectionStatus::Closed->value, ElectionStatus::Published->value, ElectionStatus::Archived->value];

        // No user is signed in: the command works across institutions, on the election's status only.
        $closedElections = Election::withoutInstitutionScope()->whereIn('status', $locked)->select('id');

        // The same reason: every institution's files are cleared, chosen by their election's status.
        GeneratedFile::withoutInstitutionScope()
            ->whereIn('election_id', $closedElections)
            ->orderBy('id')
            ->chunkById(100, function ($files) use ($disk, $locked, &$deleted, &$failedCopies): void {
                foreach ($files as $candidate) {
                    $stored = $this->delete($candidate->id, $candidate->election_id, $locked);

                    if ($stored === false) {
                        continue;
                    }

                    $deleted++;

                    if ($stored !== null) {
                        try {
                            $disk->delete($stored);
                        } catch (Throwable) {
                            // The row is gone; a copy that stays under its random name is counted.
                            $failedCopies++;
                        }
                    }
                }
            });

        $failed = 0;

        // No user is signed in: a stuck file is found in any institution, on its age only.
        GeneratedFile::withoutInstitutionScope()
            ->where('status', GeneratedFile::STATUS_QUEUED)
            ->where('created_at', '<', $stuck)
            ->orderBy('id')
            ->chunkById(100, function ($files) use ($stuck, $disk, &$failed, &$failedCopies): void {
                foreach ($files as $candidate) {
                    $stored = $this->markFailed($candidate->id, $candidate->election_id, $stuck);

                    if ($stored === false) {
                        continue;
                    }

                    $failed++;

                    // The claimed copy, or the partial of a file of codes: a failed file keeps none.
                    if ($stored !== null) {
                        try {
                            $disk->delete($stored);
                        } catch (Throwable) {
                            $failedCopies++;
                        }
                    }
                }
            });

        $this->info("Deleted {$deleted}. Marked failed {$failed}. Copies left {$failedCopies}.");

        return self::SUCCESS;
    }

    /**
     * Deletes the file's row if its election is still closed, published or archived, the election
     * row locked first (the order of the generation job).
     *
     * @param  list<string>  $locked  the statuses whose files go
     * @return string|false|null the stored name (null when there is none), false when nothing was deleted
     */
    private function delete(int $fileKey, int $electionKey, array $locked): string|false|null
    {
        return DB::transaction(function () use ($fileKey, $electionKey, $locked): string|false|null {
            // No user is signed in: the election is read and locked by its own key.
            $election = Election::withoutInstitutionScope()->whereKey($electionKey)->lockForUpdate()->first();

            if ($election === null || ! in_array($election->status->value, $locked, true)) {
                return false;
            }

            // No user is signed in: the file is read and locked by its own key.
            $file = GeneratedFile::withoutInstitutionScope()->whereKey($fileKey)->lockForUpdate()->first();

            if ($file === null) {
                return false;
            }

            $stored = $file->stored_file;
            $file->delete();

            return $stored;
        });
    }

    /**
     * Marks the file failed if it is still queued and older than the limit, under the locks.
     *
     * @return string|false|null the stored name to remove (null when there is none), false when nothing was marked
     */
    private function markFailed(int $fileKey, int $electionKey, Carbon $stuck): string|false|null
    {
        return DB::transaction(function () use ($fileKey, $electionKey, $stuck): string|false|null {
            // No user is signed in: locked by its own key, in the job's order (election first, then file).
            Election::withoutInstitutionScope()->whereKey($electionKey)->lockForUpdate()->first();

            // No user is signed in: the file is read and locked by its own key.
            $file = GeneratedFile::withoutInstitutionScope()->whereKey($fileKey)->lockForUpdate()->first();

            if ($file === null
                || $file->status !== GeneratedFile::STATUS_QUEUED
                || $file->created_at === null
                || $file->created_at->gte($stuck)) {
                return false;
            }

            $stored = $file->stored_file;

            $file->forceFill([
                'status' => GeneratedFile::STATUS_FAILED,
                'failure' => GeneratedFile::FAILURE_GENERATION,
                'stored_file' => null,
            ])->save();

            return $stored;
        });
    }
}
