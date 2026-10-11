<?php

namespace App\Jobs;

use App\Actions\Credentials\GenerateSlipFile;
use App\Models\GeneratedFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the codes and the slip PDF of ONE queued file. The files of a run go one after the
 * other: when a file is done, or has failed, this job queues the job of the next `queued` file
 * of the same election, so a file never depends on the success of its sibling. It holds the
 * file's UUID only: never a code, a name or an election's data.
 */
class GenerateSlipFileJob implements ShouldQueue
{
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

    /**
     * Where this file stands, read before the work: a file removed for want of voters has no row after.
     *
     * @return array{election: int, id: int}|null
     */
    private function position(): ?array
    {
        try {
            // Queue job: nobody is signed in, so the scope reads no institution; the file's UUID is the key.
            $file = GeneratedFile::withoutInstitutionScope()->where('uuid', $this->fileUuid)->first();
        } catch (Throwable) {
            return null;
        }

        return $file === null ? null : ['election' => $file->election_id, 'id' => $file->id];
    }

    /** @param  array{election: int, id: int}|null  $position */
    private function queueNext(?array $position): void
    {
        if ($position === null) {
            return;
        }

        try {
            // Queue job: nobody is signed in; the election is the bound. Unclaimed files only.
            $next = GeneratedFile::withoutInstitutionScope()
                ->where('election_id', $position['election'])
                ->where('status', GeneratedFile::STATUS_QUEUED)
                ->whereNull('stored_file')
                ->where('id', '>', $position['id'])
                ->orderBy('id')
                ->value('uuid');

            if (is_string($next)) {
                self::dispatch($next);
            }
        } catch (Throwable $e) {
            // The files left stay `queued` until the next run fails them (15 minutes) or the purge does.
            Log::info('credentials.file', ['outcome' => 'chain_broken', 'class' => $e::class]);
        }
    }
}
