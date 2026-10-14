<?php

namespace App\Console\Commands;

use App\Enums\VoterImportStatus;
use App\Models\VoterImport;
use App\Support\Imports\ImportTempFolder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the voter imports older than 7 days that are not running, and the imports left in
 * `importing` for more than a day (their job was lost): the file and, by cascade, the rows
 * (docs/design/database.md section 2.3). It also removes the temporary folders of checks that
 * were killed. Scheduled daily (routes/console.php).
 */
class PurgeVoterImports extends Command
{
    /** Imports older than this many days go. */
    public const DAYS = 7;

    /** An import still `importing` after this many hours without a change is taken for lost. */
    public const STUCK_HOURS = 24;

    protected $signature = 'imports:purge';

    protected $description = 'Delete the voter imports older than 7 days that are not importing, and those stuck importing';

    public function handle(): int
    {
        $disk = Storage::disk('imports');
        $deleted = 0;
        $old = Carbon::now('UTC')->subDays(self::DAYS);
        $stuck = Carbon::now('UTC')->subHours(self::STUCK_HOURS);

        // No user is signed in: the command works across institutions, on the age of the import only.
        VoterImport::withoutInstitutionScope()
            ->where(function ($query) use ($old, $stuck): void {
                $query->where(fn ($q) => $q->where('created_at', '<', $old)->where('status', '!=', VoterImportStatus::Importing->value))
                    ->orWhere(fn ($q) => $q->where('status', VoterImportStatus::Importing->value)->where('updated_at', '<', $stuck));
            })
            ->orderBy('id')
            ->chunkById(100, function ($candidates) use ($disk, $old, $stuck, &$deleted): void {
                foreach ($candidates as $candidate) {
                    $stored = $this->purge($candidate->id, $old, $stuck);

                    if ($stored !== null) {
                        $disk->delete($stored);
                        $deleted++;
                    }
                }
            });

        // A check killed before its `finally` leaves its folder behind.
        ImportTempFolder::removeOlderThan(86400);

        $this->info("Deleted {$deleted}.");

        return self::SUCCESS;
    }

    /**
     * Reads the import again under a lock and deletes it only if it still qualifies (it may have
     * been confirmed, or finished, since the list was read).
     *
     * @return string|null the stored file name when the import was deleted
     */
    private function purge(int $key, Carbon $old, Carbon $stuck): ?string
    {
        $uuid = null;

        $stored = DB::transaction(function () use ($key, $old, $stuck, &$uuid): ?string {
            // The same reason as the list: no user is signed in, the import is read by its own key.
            $import = VoterImport::withoutInstitutionScope()->whereKey($key)->lockForUpdate()->first();

            if ($import === null) {
                return null;
            }

            if ($import->status === VoterImportStatus::Importing) {
                if ($import->updated_at === null || $import->updated_at->gte($stuck)) {
                    return null;
                }

                $import->forceFill([
                    'status' => VoterImportStatus::Failed,
                    'failure' => 'import_failed',
                    'finished_at' => Carbon::now('UTC'),
                ])->save();
            } elseif ($import->created_at === null || $import->created_at->gte($old)) {
                return null;
            }

            $uuid = $import->uuid;
            $import->delete();

            return $import->stored_file;
        });

        if ($stored !== null && is_string($uuid)) {
            ImportTempFolder::remove($uuid);
        }

        return $stored;
    }
}
