<?php

namespace App\Jobs\Concerns;

use App\Jobs\GenerateCodesFileJob;
use App\Jobs\GenerateSlipFileJob;
use App\Models\GeneratedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The files of a run go one after the other: when a file is done, or has failed, its job queues
 * the job of the next `queued` file of the same election (the file of codes is the last).
 *
 * @property string $fileUuid
 */
trait ChainsToNextFile
{
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
            // Queue job: nobody is signed in; the election is the bound. A slip file is taken once
            // it holds a stored name; a file of codes holds its partial from the first slip on.
            $next = GeneratedFile::withoutInstitutionScope()
                ->where('election_id', $position['election'])
                ->where('status', GeneratedFile::STATUS_QUEUED)
                ->where(function ($query): void {
                    $query->whereNull('stored_file')->orWhereIn('kind', GeneratedFile::CODE_KINDS);
                })
                ->where('id', '>', $position['id'])
                ->orderBy('id')
                ->first(['uuid', 'kind']);

            if ($next === null) {
                return;
            }

            if (in_array($next->kind, GeneratedFile::CODE_KINDS, true)) {
                GenerateCodesFileJob::dispatch($next->uuid);
            } else {
                GenerateSlipFileJob::dispatch($next->uuid);
            }
        } catch (Throwable $e) {
            // The files left stay `queued` until the next run fails them (15 minutes) or the purge does.
            Log::info('credentials.file', ['outcome' => 'chain_broken', 'class' => $e::class]);
        }
    }
}
