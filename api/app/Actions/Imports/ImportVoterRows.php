<?php

namespace App\Actions\Imports;

use App\Enums\ImportRowStatus;
use App\Enums\VoterImportStatus;
use App\Exceptions\ApiException;
use App\Models\Election;
use App\Models\Voter;
use App\Models\VoterImport;
use App\Models\VoterImportRow;
use App\Support\Imports\Fold;
use App\Support\Voters\GroupByName;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Writes the valid rows of a confirmed import into its election, in chunks of 500. Each chunk is
 * one transaction that locks the election row first (as POST voters), judges its rows again
 * against the voters that exist at that moment, creates the groups it needs and writes the
 * voters. A row that turned invalid since the check is marked so and goes to the error file.
 *
 * Called by the queued job, so nobody is signed in: everything is read by the import's own
 * election and written through the models with the institution named. Nothing of the file is
 * ever logged: not a cell, not an exception message.
 */
final class ImportVoterRows
{
    /** The rows of one transaction. */
    public const CHUNK = 500;

    public function __construct(private readonly GroupByName $groups) {}

    /**
     * How many chunks `$rows` rows make.
     */
    public static function chunks(int $rows): int
    {
        return $rows <= 0 ? 0 : intdiv($rows + self::CHUNK - 1, self::CHUNK);
    }

    public function __invoke(VoterImport $import): void
    {
        try {
            $this->run($import);
        } catch (Throwable) {
            // The finished chunks stay; the exception may quote a cell, so it is not logged.
            $this->fail($import);

            return;
        }

        Log::info('voter_import.run', ['outcome' => 'done']);
    }

    private function run(VoterImport $import): void
    {
        // The job has no signed-in user: the election is read by the import's own key.
        $start = Election::withoutInstitutionScope()->whereKey($import->election_id)->first();

        if ($start === null) {
            throw new ApiException(404, 'not_found');
        }

        $start->assertVotersDeletable();

        // The rows rejected at the check are handled from the start: they are never written.
        // The import's own rows, read by its key (nobody is signed in).
        $rejected = VoterImportRow::withoutInstitutionScope()
            ->where('voter_import_id', $import->id)
            ->where('status', '!=', ImportRowStatus::Ok->value)
            ->count();

        $import->forceFill(['processed_rows' => $rejected, 'imported_rows' => 0])->save();

        $after = 0;

        while (true) {
            // The same reason: the import's own rows, in file order.
            $rows = VoterImportRow::withoutInstitutionScope()
                ->where('voter_import_id', $import->id)
                ->where('status', ImportRowStatus::Ok->value)
                ->where('line', '>', $after)
                ->orderBy('line')
                ->limit(self::CHUNK)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $after = (int) $rows->last()->line;

            DB::transaction(fn () => $this->chunk($import, $rows->values()->all()));
        }

        $import->forceFill([
            'status' => VoterImportStatus::Done,
            'failure' => null,
            'finished_at' => Carbon::now('UTC'),
        ])->save();

        // Done: the preview and the error file read the rows, not the file.
        try {
            Storage::disk('imports')->delete($import->stored_file);
        } catch (Throwable) {
            // A file that stays is removed by the clean-up; the import is done all the same.
        }
    }

    /**
     * One chunk, in the caller's transaction.
     *
     * @param  array<int, VoterImportRow>  $rows
     */
    private function chunk(VoterImport $import, array $rows): void
    {
        // Locked first: no voter, group or other import of this election is written while the chunk is.
        $election = Election::withoutInstitutionScope()->whereKey($import->election_id)->lockForUpdate()->first();

        if ($election === null) {
            throw new ApiException(404, 'not_found');
        }

        $election->assertVotersDeletable();

        $identifiers = [];
        $emails = [];
        $count = 0;

        // The election's voters as they are now (the same reason as above).
        foreach (Voter::withoutInstitutionScope()->where('election_id', $election->id)->get(['identifier', 'email']) as $voter) {
            $count++;

            if ($voter->identifier !== null) {
                $identifiers[Fold::key($voter->identifier)] = true;
            }

            if ($voter->email !== null) {
                $emails[Fold::key($voter->email)] = true;
            }
        }

        $written = 0;
        $invalid = 0;
        $duplicates = 0;

        foreach ($rows as $row) {
            $reasons = [];
            $identifierKey = $row->identifier === null ? null : Fold::key($row->identifier);
            $emailKey = $row->email === null ? null : Fold::key($row->email);

            if ($identifierKey !== null && isset($identifiers[$identifierKey])) {
                $reasons[] = 'identifier_exists';
            }

            if ($emailKey !== null && isset($emails[$emailKey])) {
                $reasons[] = 'email_exists';
            }

            if ($reasons === []) {
                if ($count >= Voter::LIMIT) {
                    // The election filled up since the confirmation: the import stops, this chunk is undone.
                    throw new ApiException(409, 'voter_limit_reached');
                }

                $reasons = $this->write($election, $row);
            }

            if ($reasons === []) {
                $count++;
                $written++;

                if ($identifierKey !== null) {
                    $identifiers[$identifierKey] = true;
                }

                if ($emailKey !== null) {
                    $emails[$emailKey] = true;
                }

                continue;
            }

            $duplicate = in_array('identifier_exists', $reasons, true) || in_array('email_exists', $reasons, true);
            $duplicate ? $duplicates++ : $invalid++;

            $row->forceFill([
                'status' => $duplicate ? ImportRowStatus::Duplicate : ImportRowStatus::Invalid,
                'reasons' => $reasons,
            ])->save();
        }

        $import->forceFill([
            'processed_rows' => $import->processed_rows + count($rows),
            'imported_rows' => $import->imported_rows + $written,
            'valid_rows' => $import->valid_rows - $invalid - $duplicates,
            'invalid_rows' => $import->invalid_rows + $invalid + $duplicates,
            'duplicate_rows' => $import->duplicate_rows + $duplicates,
        ])->save();
    }

    /**
     * Writes one voter and its group in a savepoint, so that a refusal leaves nothing of the row.
     *
     * @return list<string> the reasons the row is refused; empty when it was written
     */
    private function write(Election $election, VoterImportRow $row): array
    {
        try {
            DB::transaction(function () use ($election, $row): void {
                $group = ($this->groups)($election, $row->group_name);

                $voter = new Voter([
                    'full_name' => (string) $row->full_name,
                    'identifier' => $row->identifier,
                    'email' => $row->email === null ? null : mb_strtolower($row->email),
                    'phone' => $row->phone,
                ]);
                $voter->election_id = $election->id;
                $voter->institution_id = $election->institution_id;
                $voter->voter_group_id = $group?->id;
                $voter->save();
            });
        } catch (UniqueConstraintViolationException) {
            // The index saw what the comparison keys did not (the table's own collation).
            // The same reason as above: this election's voters.
            $identifier = $row->identifier !== null && Voter::withoutInstitutionScope()
                ->where('election_id', $election->id)->where('identifier', $row->identifier)->exists();

            return [$identifier ? 'identifier_exists' : 'email_exists'];
        } catch (ApiException $e) {
            if ($e->errorCode === 'group_limit_reached') {
                return ['group_limit_reached'];
            }

            throw $e;
        }

        return [];
    }

    /** The import ends `failed`; what the finished chunks wrote stays and is counted. */
    private function fail(VoterImport $import): void
    {
        // Read again by its key: the model in memory may hold a counter of a chunk that was undone.
        $current = VoterImport::withoutInstitutionScope()->whereKey($import->getKey())->first();

        if ($current === null) {
            return;
        }

        $current->forceFill([
            'status' => VoterImportStatus::Failed,
            'failure' => 'import_failed',
            'finished_at' => Carbon::now('UTC'),
        ])->save();

        Log::info('voter_import.run', ['outcome' => 'failed']);
    }
}
