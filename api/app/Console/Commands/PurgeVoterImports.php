<?php

namespace App\Console\Commands;

use App\Enums\VoterImportStatus;
use App\Models\VoterImport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the voter imports older than 7 days that are not running: the file and, by cascade,
 * the rows (docs/design/database.md section 2.3). Scheduled daily (routes/console.php).
 */
class PurgeVoterImports extends Command
{
    /** Imports older than this many days go. */
    public const DAYS = 7;

    protected $signature = 'imports:purge';

    protected $description = 'Delete the voter imports older than 7 days that are not importing';

    public function handle(): int
    {
        $disk = Storage::disk('imports');
        $deleted = 0;

        // No user is signed in: the command works across institutions, on the age of the import only.
        VoterImport::withoutInstitutionScope()
            ->where('created_at', '<', Carbon::now('UTC')->subDays(self::DAYS))
            ->where('status', '!=', VoterImportStatus::Importing->value)
            ->orderBy('id')
            ->chunkById(100, function ($imports) use ($disk, &$deleted): void {
                foreach ($imports as $import) {
                    $disk->delete($import->stored_file);
                    $import->delete();
                    $deleted++;
                }
            });

        $this->info("Deleted {$deleted}.");

        return self::SUCCESS;
    }
}
