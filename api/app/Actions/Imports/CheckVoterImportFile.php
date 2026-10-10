<?php

namespace App\Actions\Imports;

use App\Enums\ImportRowStatus;
use App\Enums\VoterImportStatus;
use App\Models\Voter;
use App\Models\VoterGroup;
use App\Models\VoterImport;
use App\Models\VoterImportRow;
use App\Support\Imports\ColumnMapper;
use App\Support\Imports\ImportTempFolder;
use App\Support\Imports\RowChecker;
use App\Support\Imports\VoterFileReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads the stored file of an import, judges every row and writes the result: the rows, the
 * counts, the groups the import would create, and the status `checked` (or `failed` with a
 * code). Nothing is written to the election. Called by the queued job, so nobody is signed in:
 * the import is read without the tenant scope and everything it touches is limited by the
 * import's own election.
 *
 * Nothing of the file is ever logged: not a cell, a name, an email or an identifier.
 */
final class CheckVoterImportFile
{
    /** Lines read at most, empty ones included: the header, 10 000 rows and some blank lines. */
    public const MAX_LINES = 11000;

    /**
     * What a value kept for the preview and the error file is cut to: its field's maximum length
     * plus one character (the row is invalid for its length anyway; the checks ran on the whole text).
     */
    public const KEPT = ['full_name' => 151, 'group' => 101, 'identifier' => 51, 'email' => 256, 'phone' => 31];

    public function __construct(private readonly ColumnMapper $mapper, private readonly VoterFileReader $reader) {}

    public function __invoke(VoterImport $import): void
    {
        // The reader's working files go to a folder of this import, always removed when the check ends.
        $folder = ImportTempFolder::make($import->uuid);

        try {
            $outcome = $this->read($import, $folder);
        } finally {
            ImportTempFolder::remove($import->uuid);
        }

        // The import may have been deleted, or checked by another worker, while the file was read.
        DB::transaction(function () use ($import, $outcome): void {
            // The job has no signed-in user: the import is read by its own key, whatever its institution.
            $locked = VoterImport::withoutInstitutionScope()->whereKey($import->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== VoterImportStatus::Uploaded) {
                return;
            }

            // The same reason: the rows are those of this import only.
            VoterImportRow::withoutInstitutionScope()->where('voter_import_id', $locked->id)->delete();

            // Each row is written through its model, so that the tenant rules apply (never a builder-level insert).
            foreach ($outcome['rows'] as $row) {
                $record = new VoterImportRow;
                $record->forceFill([
                    'institution_id' => $locked->institution_id,
                    'voter_import_id' => $locked->id,
                    'line' => $row['line'],
                    'status' => $row['status'],
                    'reasons' => $row['reasons'],
                    'full_name' => $row['values']['full_name'],
                    'group_name' => $row['values']['group'],
                    'identifier' => $row['values']['identifier'],
                    'email' => $row['values']['email'],
                    'phone' => $row['values']['phone'],
                    'raw' => $row['raw'],
                ])->save();
            }

            $locked->forceFill([
                'columns' => $outcome['columns'],
                'mapping' => $outcome['mapping'],
                'status' => $outcome['failure'] === null ? VoterImportStatus::Checked : VoterImportStatus::Failed,
                'failure' => $outcome['failure'],
                'total_rows' => $outcome['total'],
                'valid_rows' => $outcome['valid'],
                'invalid_rows' => $outcome['invalid'],
                'duplicate_rows' => $outcome['duplicates'],
                'new_groups' => $outcome['new_groups'],
                'processed_rows' => 0,
                'imported_rows' => 0,
                'finished_at' => $outcome['failure'] === null ? null : Carbon::now('UTC'),
            ])->save();
        });

        Log::info('voter_import.check', ['outcome' => $outcome['failure'] ?? 'checked']);
    }

    /**
     * The whole check in memory (10 000 rows at most), before anything is written.
     *
     * @return array{columns: list<string>|null, mapping: array<string, int|null>|null, failure: string|null, total: int, valid: int, invalid: int, duplicates: int, new_groups: list<string>, rows: list<array{line: int, status: ImportRowStatus, reasons: list<string>, values: array{full_name: string|null, group: string|null, identifier: string|null, email: string|null, phone: string|null}, raw: array<string, string|null>}>}
     */
    private function read(VoterImport $import, string $folder): array
    {
        $outcome = [
            'columns' => null, 'mapping' => null, 'failure' => null,
            'total' => 0, 'valid' => 0, 'invalid' => 0, 'duplicates' => 0, 'new_groups' => [], 'rows' => [],
        ];

        $disk = Storage::disk('imports');

        if (! $disk->exists($import->stored_file)) {
            return ['failure' => 'unreadable'] + $outcome;
        }

        // The election's own voters and groups, read once: a file of 10 000 rows asks the database nothing more.
        $voters = Voter::withoutInstitutionScope()
            ->where('election_id', $import->election_id)
            ->get(['identifier', 'email']);
        $identifiers = [];
        $emails = [];

        foreach ($voters as $voter) {
            $identifiers[] = $voter->identifier;
            $emails[] = $voter->email;
        }

        $groupKeys = [];

        // No user is signed in (a queue worker): the groups are those of the import's own election.
        foreach (VoterGroup::withoutInstitutionScope()->where('election_id', $import->election_id)->pluck('name_key') as $key) {
            if (is_string($key)) {
                $groupKeys[] = $key;
            }
        }

        $checker = new RowChecker($identifiers, $emails, $groupKeys);

        $mapping = null;

        try {
            foreach ($this->reader->lines($disk->path($import->stored_file), $folder) as $line => $cells) {
                if ($line > self::MAX_LINES) {
                    return ['failure' => 'too_many_rows', 'rows' => [], 'total' => 0, 'valid' => 0, 'invalid' => 0, 'duplicates' => 0, 'new_groups' => []] + $outcome;
                }

                if ($line === 1) {
                    $columns = $this->header($cells);

                    if ($columns === []) {
                        return ['failure' => 'no_header'] + $outcome;
                    }

                    // At most 50 cells are stored, even for a header that is refused.
                    $outcome['columns'] = array_slice($columns, 0, VoterFileReader::MAX_COLUMNS);

                    if (count($columns) > VoterFileReader::MAX_COLUMNS) {
                        return ['failure' => 'unreadable'] + $outcome;
                    }

                    $mapping = $this->mappingFor($import, $columns);
                    $outcome['mapping'] = $mapping;

                    if ($mapping['full_name'] === null) {
                        return ['failure' => 'no_full_name_column'] + $outcome;
                    }

                    continue;
                }

                if ($mapping === null || $this->isEmpty($cells)) {
                    continue;
                }

                if ($outcome['total'] >= VoterImport::MAX_ROWS) {
                    return ['failure' => 'too_many_rows', 'rows' => [], 'total' => 0, 'valid' => 0, 'invalid' => 0, 'duplicates' => 0, 'new_groups' => []] + $outcome;
                }

                $raw = [];
                $values = [];

                foreach (VoterImport::FIELDS as $field) {
                    $index = $mapping[$field];
                    $cell = $index === null ? null : ($cells[$index] ?? null);
                    $raw[$field] = $cell === null ? null : mb_substr($cell, 0, self::KEPT[$field]);
                    $text = $cell === null ? '' : trim($cell);
                    $values[$field] = $text === '' ? null : $text;
                }

                $judged = $checker->check($line, $values);

                $outcome['total']++;

                if ($judged['status'] === ImportRowStatus::Ok) {
                    $outcome['valid']++;
                } else {
                    $outcome['invalid']++;
                }

                if ($judged['status'] === ImportRowStatus::Duplicate) {
                    $outcome['duplicates']++;
                }

                $kept = [];

                foreach (self::KEPT as $field => $length) {
                    $value = $judged['values'][$field];
                    $kept[$field] = $value === null ? null : mb_substr($value, 0, $length);
                }

                $outcome['rows'][] = [
                    'line' => $line,
                    'status' => $judged['status'],
                    'reasons' => $judged['reasons'],
                    'values' => [
                        'full_name' => $kept['full_name'], 'group' => $kept['group'], 'identifier' => $kept['identifier'],
                        'email' => $kept['email'], 'phone' => $kept['phone'],
                    ],
                    'raw' => $raw,
                ];
            }
        } catch (Throwable) {
            // The message may quote the file: it is dropped.
            return ['failure' => 'unreadable', 'rows' => [], 'total' => 0, 'valid' => 0, 'invalid' => 0, 'duplicates' => 0, 'new_groups' => []] + $outcome;
        }

        if ($mapping === null) {
            return ['failure' => 'no_header'] + $outcome;
        }

        $outcome['new_groups'] = $checker->newGroups();

        return $outcome;
    }

    /**
     * The header cells as text, without the empty cells at the end; empty when the line holds nothing.
     *
     * @param  list<string|null>  $cells
     * @return list<string>
     */
    private function header(array $cells): array
    {
        $columns = array_map(fn (?string $cell): string => $cell === null ? '' : mb_substr(trim($cell), 0, 200), $cells);

        while ($columns !== [] && end($columns) === '') {
            array_pop($columns);
        }

        return $columns;
    }

    /**
     * The mapping the user chose when there is one that fits the file, else the proposal.
     *
     * @param  list<string>  $columns
     * @return array<string, int|null>
     */
    private function mappingFor(VoterImport $import, array $columns): array
    {
        $stored = $import->mapping;

        if (is_array($stored) && $this->mapper->isValid($stored, count($columns))) {
            return $stored;
        }

        return $this->mapper->propose($columns);
    }

    /** @param  list<string|null>  $cells */
    private function isEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
