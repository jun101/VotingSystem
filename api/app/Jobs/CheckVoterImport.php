<?php

namespace App\Jobs;

use App\Actions\Imports\CheckVoterImportFile;
use App\Enums\VoterImportStatus;
use App\Models\VoterImport;
use App\Support\Imports\ImportTempFolder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Checks an uploaded voter file in the background (docs/design/architecture.md section 8). It
 * holds the import's UUID, not the model: a queue worker has nobody signed in, so the tenant
 * scope would find nothing to restore. If the import was deleted meanwhile, it ends without
 * writing.
 */
class CheckVoterImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** A 10 000-row file checks in well under this; the Redis `retry_after` is longer. */
    public int $timeout = 80;

    /** A check that failed is not repeated: the import ends `failed` and the user uploads again. */
    public int $tries = 1;

    public function __construct(public readonly string $importUuid) {}

    public function handle(CheckVoterImportFile $check): void
    {
        // The job has no signed-in user: the import is found by its own UUID.
        $import = VoterImport::withoutInstitutionScope()->where('uuid', $this->importUuid)->first();

        if ($import === null || $import->status !== VoterImportStatus::Uploaded) {
            return;
        }

        $check($import);
    }

    /** A crash or a timeout: the import must not stay `uploaded` for ever. The error is not logged here (it may quote a cell). */
    public function failed(?Throwable $exception): void
    {
        // The reader's working files stay behind when the job is killed.
        ImportTempFolder::remove($this->importUuid);

        // The same reason as in `handle`.
        VoterImport::withoutInstitutionScope()
            ->where('uuid', $this->importUuid)
            ->where('status', VoterImportStatus::Uploaded->value)
            ->update(['status' => VoterImportStatus::Failed->value, 'failure' => 'unreadable', 'finished_at' => Carbon::now('UTC')]);
    }
}
