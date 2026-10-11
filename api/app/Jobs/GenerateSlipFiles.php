<?php

namespace App\Jobs;

use App\Actions\Credentials\GenerateSlipFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Writes the codes and the slip PDFs of the queued files of one run, one file after another.
 * It holds the files' UUIDs only: never a code, a name or an election's data. A file that fails
 * is marked and the next one goes on.
 */
class GenerateSlipFiles implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Shorter than the Redis `retry_after`: a slow run is stopped, never started twice. */
    public int $timeout = 80;

    /** A run is not repeated: a file is taken only while it is `queued`, so a retry could not help. */
    public int $tries = 1;

    /** @param  list<string>  $fileUuids */
    public function __construct(public readonly array $fileUuids) {}

    public function handle(GenerateSlipFile $generate): void
    {
        foreach ($this->fileUuids as $uuid) {
            $generate($uuid);
        }
    }

    /** A crash or a timeout: the files not done must not stay `queued` for ever. */
    public function failed(?Throwable $exception): void
    {
        $generate = app(GenerateSlipFile::class);

        foreach ($this->fileUuids as $uuid) {
            $generate->fail($uuid);
        }
    }
}
