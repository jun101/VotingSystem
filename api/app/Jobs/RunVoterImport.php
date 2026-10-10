<?php

namespace App\Jobs;

use App\Actions\Imports\ImportVoterRows;
use App\Enums\VoterImportStatus;
use App\Models\VoterImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Writes a confirmed import's valid rows into its election in the background. It holds the
 * import's UUID, not the model: a queue worker has nobody signed in, so the tenant scope would
 * find nothing to restore.
 */
class RunVoterImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Shorter than the Redis `retry_after`: a slow import is stopped, never started twice. */
    public int $timeout = 80;

    /** An import that failed is not repeated: the finished chunks stay, so a retry would reject them as duplicates. */
    public int $tries = 1;

    public function __construct(public readonly string $importUuid) {}

    public function handle(ImportVoterRows $import): void
    {
        // The job has no signed-in user: the import is found by its own UUID.
        $row = VoterImport::withoutInstitutionScope()->where('uuid', $this->importUuid)->first();

        if ($row === null || $row->status !== VoterImportStatus::Importing) {
            return;
        }

        $import($row);
    }

    /** A crash or a timeout: the import must not stay `importing` for ever. The error is not logged (it may quote a cell). */
    public function failed(?Throwable $exception): void
    {
        // The same reason as in `handle`.
        VoterImport::withoutInstitutionScope()
            ->where('uuid', $this->importUuid)
            ->where('status', VoterImportStatus::Importing->value)
            ->update(['status' => VoterImportStatus::Failed->value, 'failure' => 'import_failed', 'finished_at' => Carbon::now('UTC')]);
    }
}
