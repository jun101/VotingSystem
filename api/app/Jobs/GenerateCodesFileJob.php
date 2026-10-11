<?php

namespace App\Jobs;

use App\Actions\Credentials\GenerateCodesFile;
use App\Actions\Credentials\GenerateSlipFile;
use App\Jobs\Concerns\ChainsToNextFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes the self-distribution file of a run (Excel or CSV) from the rows the slip jobs handed
 * over. It is the last job of the chain. It holds the file's UUID only: never a code or a name.
 */
class GenerateCodesFileJob implements ShouldQueue
{
    use ChainsToNextFile;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** A file of 10 000 rows is written in a few seconds; shorter than the Redis `retry_after`. */
    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public readonly string $fileUuid) {}

    public function handle(GenerateCodesFile $generate): void
    {
        $position = $this->position();

        $generate($this->fileUuid);

        $this->queueNext($position);
    }

    /** A crash or a timeout: the file must not stay `queued` for ever. */
    public function failed(?Throwable $exception): void
    {
        try {
            DB::rollBack(0);
        } catch (Throwable) {
            // The connection is gone: the next statement opens a new one.
        }

        $position = $this->position();

        app(GenerateSlipFile::class)->fail($this->fileUuid, $exception);

        $this->queueNext($position);
    }
}
