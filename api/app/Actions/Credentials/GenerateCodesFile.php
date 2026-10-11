<?php

namespace App\Actions\Credentials;

use App\Exceptions\ApiException;
use App\Models\Election;
use App\Models\GeneratedFile;
use App\Support\Credentials\CodesFileWriter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * The job's work for the self-distribution file of a run, the last file of the chain: it reads the
 * partial the slip jobs built, checks that it holds one row for every voter the ready slip files
 * of the run served, sorts it by group then name (the voters with no group last), writes the
 * Excel or CSV, encrypts it and marks the file `ready`, all under the election's row and the
 * file's row. No slip served anyone: the file's row is removed. A failure marks it `failed` and
 * removes the partial and the copy; nothing of a code, a name or a link is logged.
 */
final class GenerateCodesFile
{
    public function __construct(
        private readonly CodesFileWriter $writer,
        private readonly GenerateSlipFile $slips,
    ) {}

    public function __invoke(string $fileUuid): void
    {
        // The job has no signed-in user: the file is found by its own UUID.
        $file = GeneratedFile::withoutInstitutionScope()->where('uuid', $fileUuid)->first();

        if ($file === null || $file->status !== GeneratedFile::STATUS_QUEUED || ! in_array($file->kind, GeneratedFile::CODE_KINDS, true)) {
            return;
        }

        $written = null;
        $partial = null;

        try {
            $outcome = DB::transaction(function () use ($file, &$written, &$partial): string {
                // No user is signed in: the election is locked by its own key, first, as every writer.
                $election = Election::withoutInstitutionScope()->whereKey($file->election_id)->lockForUpdate()->first();

                if ($election === null) {
                    throw new ApiException(404, 'not_found');
                }

                $election->assertCodesOpen();

                // No user is signed in: the file is read and locked by its own key.
                $current = GeneratedFile::withoutInstitutionScope()->whereKey($file->id)->lockForUpdate()->first();

                if ($current === null || $current->status !== GeneratedFile::STATUS_QUEUED) {
                    return 'taken';
                }

                if ($current->stored_file === null) {
                    // No slip file served anyone: there is nothing to give.
                    $current->delete();

                    return 'removed';
                }

                $partial = $current->stored_file;
                $disk = Storage::disk('credentials');
                $rows = AppendCodeRows::decode(Crypt::decryptString((string) $disk->get($partial)));

                // No user is signed in: the slip files of the run are read by the election and the instant.
                $expected = (int) GeneratedFile::withoutInstitutionScope()
                    ->where('election_id', $current->election_id)
                    ->where('kind', GeneratedFile::KIND_SLIPS)
                    ->where('status', GeneratedFile::STATUS_READY)
                    ->where('created_at', $current->created_at)
                    ->sum('voter_count');

                // A slip file whose rows could not be handed over would leave voters out: no incomplete file.
                if ($expected !== count($rows)) {
                    throw new RuntimeException('The rows do not match the slips.');
                }

                $rows = $this->sorted($rows);
                $xlsx = $current->kind === GeneratedFile::KIND_CODES_XLSX;
                $bytes = $xlsx ? $this->writer->xlsx($rows, $election->language) : $this->writer->csv($rows, $election->language);

                $name = Uuid::uuid4()->toString();
                $disk->put($name, Crypt::encryptString($bytes));
                $written = $name;

                $current->forceFill([
                    'stored_file' => $name,
                    'status' => GeneratedFile::STATUS_READY,
                    'failure' => null,
                    'size_bytes' => strlen($bytes),
                    'voter_count' => count($rows),
                ])->save();

                return 'ready';
            });
        } catch (Throwable $e) {
            // Rolled back: the copy written meanwhile has no row, the partial is released by `fail`.
            $this->remove($written);
            $this->slips->fail($fileUuid, $e);

            return;
        }

        if ($outcome === 'ready') {
            // After the commit: the partial is no longer needed.
            $this->remove($partial);
        }

        Log::info('credentials.codes_file', ['outcome' => $outcome]);
    }

    /**
     * By group name (accents and case ignored, the voters with no group last), then by name.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $rows
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function sorted(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $index => $row) {
            $keyed[] = [
                $row[1] === '' ? 1 : 0,
                Str::lower(Str::ascii($row[1])),
                Str::lower(Str::ascii($row[0])),
                $index,
                $row,
            ];
        }

        usort($keyed, fn (array $a, array $b): int => [$a[0], $a[1], $a[2], $a[3]] <=> [$b[0], $b[1], $b[2], $b[3]]);

        return array_map(fn (array $one): array => $one[4], $keyed);
    }

    private function remove(?string $name): void
    {
        if ($name === null) {
            return;
        }

        try {
            Storage::disk('credentials')->delete($name);
        } catch (Throwable) {
            // Nothing refers to it: an orphan under a random name at worst.
        }
    }
}
