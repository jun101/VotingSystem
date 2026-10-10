<?php

namespace App\Http\Resources;

use App\Models\VoterImport;
use Illuminate\Http\Request;

/**
 * An import as docs/api/voter-imports/GET-voter-imports-{import}.md shows it. The election is
 * named by its UUID in a field `election`; no numeric key, no stored file name.
 *
 * @property VoterImport $resource
 */
final class VoterImportResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        $import = $this->resource;

        return [
            'election' => $import->election?->uuid,
            'status' => $import->status->value,
            'original_name' => $import->original_name,
            'columns' => $import->columns ?? [],
            'mapping' => $import->mapping,
            'total_rows' => $import->total_rows,
            'valid_rows' => $import->valid_rows,
            'invalid_rows' => $import->invalid_rows,
            'duplicate_rows' => $import->duplicate_rows,
            'new_groups' => $import->new_groups ?? [],
            'processed_rows' => $import->processed_rows,
            'imported_rows' => $import->imported_rows,
            'failure' => $import->failure,
            'has_error_file' => $import->hasErrorFile(),
            'created_at' => $import->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'finished_at' => $import->finished_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
