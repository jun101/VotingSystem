<?php

namespace App\Jobs;

use App\Actions\Credentials\GenerateSlipFile;
use App\Jobs\Concerns\ChainsToNextFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes the codes and the slip PDF of ONE queued file. The files of a run go one after the
 * other: when a file is done, or has failed, this job queues the job of the next `queued` file
 * of the same election, so a file never depends on the success of its sibling. It holds the
 * file's UUID only: never a code, a name or an election's data.
 */
class GenerateSlipFileJob implements ShouldQueue
{
    use ChainsToNextFile;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** A file of 1 000 slips takes about 12 s; shorter than the Redis `retry_after` (180 s). */
    public int $timeout = 120;

    /** A file is taken only while it is `queued` and unclaimed, so a retry could not help. */
    public int $tries = 1;

    public function __construct(public readonly string $fileUuid) {}

    public function handle(GenerateSlipFile $generate): void
    {
        $position = $this->position();

        $generate($this->fileUuid);

        $this->queueNext($position);
    }

    /** A crash or a timeout: the file must not stay `queued` for ever, and the run goes on. */
    public function failed(?Throwable $exception): void
    {
        try {
            // A worker stopped in the middle of the first phase leaves its transaction open: not relied on.
            DB::rollBack(0);
        } catch (Throwable) {
            // The connection is gone: the next statement opens a new one.
        }

        $position = $this->position();

        app(GenerateSlipFile::class)->fail($this->fileUuid, $exception);

        $this->queueNext($position);
    }
}
