<?php

namespace App\Actions\Credentials;

use App\Models\Election;
use App\Models\GeneratedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Hands the rows of the voters a slip file served to the self-distribution file of the same run.
 * The codes are never stored, so the file is assembled from partials: an encrypted JSON list kept
 * on the `credentials` disk under the file's `stored_file` name, read, extended and written again
 * under the locks (the election's row first, then the file of codes). The partial is replaced by
 * the final file when the codes job has written it.
 *
 * A run with no file of codes costs one query.
 */
final class AppendCodeRows
{
    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $rows  name, group (empty for none), code, link
     */
    public function __invoke(GeneratedFile $slips, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        // The name written by this call and what it replaced: undone when the transaction does not commit.
        $written = null;
        $previous = null;

        try {
            $this->append($slips, $rows, $written, $previous);
        } catch (Throwable $e) {
            $this->undo($written, $previous);

            throw $e;
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $rows
     * @param  string|null  $written  set to the stored name once the partial was written
     * @param  string|null  $previous  set to the ciphertext the partial had before (null: a new partial)
     */
    private function append(GeneratedFile $slips, array $rows, ?string &$written, ?string &$previous): void
    {
        DB::transaction(function () use ($slips, $rows, &$written, &$previous): void {
            // No user is signed in: the election is locked by its own key, first, as every writer.
            Election::withoutInstitutionScope()->whereKey($slips->election_id)->lockForUpdate()->first();

            // Queue job: nobody is signed in; the election and the run (its identifier, or the instant for a file without one) are the bounds.
            $codes = GeneratedFile::withoutInstitutionScope()
                ->where('election_id', $slips->election_id)
                ->whereIn('kind', GeneratedFile::CODE_KINDS)
                ->where('status', GeneratedFile::STATUS_QUEUED)
                ->when(
                    $slips->run !== null,
                    fn ($query) => $query->where('run', $slips->run),
                    fn ($query) => $query->whereNull('run')->where('created_at', $slips->created_at),
                )
                ->lockForUpdate()
                ->first();

            // No file of codes was asked for, or it failed meanwhile: nothing to keep.
            if ($codes === null) {
                return;
            }

            $disk = Storage::disk('credentials');
            $name = $codes->stored_file;
            $all = [];

            if ($name !== null) {
                $previous = (string) $disk->get($name);
                $all = $this->decode(Crypt::decryptString($previous));
            }

            array_push($all, ...$rows);

            $name ??= Uuid::uuid4()->toString();
            $disk->put($name, Crypt::encryptString(json_encode($all, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
            $written = $name;

            if ($codes->stored_file === null) {
                $codes->forceFill(['stored_file' => $name])->save();
            }
        });
    }

    /** A failed call leaves the partial as it was: a new one is removed, an extended one gets its old content back. */
    private function undo(?string $written, ?string $previous): void
    {
        if ($written === null) {
            return;
        }

        try {
            $disk = Storage::disk('credentials');

            if ($previous === null) {
                $disk->delete($written);
            } else {
                $disk->put($written, $previous);
            }
        } catch (Throwable) {
            // Nothing refers to a new name; an old partial that cannot be restored fails its file's check.
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     *
     * @throws JsonException
     */
    public static function decode(string $json): array
    {
        /** @var list<array{0: string, 1: string, 2: string, 3: string}> $rows */
        $rows = json_decode($json, true, 4, JSON_THROW_ON_ERROR);

        return $rows;
    }
}
