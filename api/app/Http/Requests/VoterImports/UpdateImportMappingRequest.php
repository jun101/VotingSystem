<?php

namespace App\Http\Requests\VoterImports;

use App\Models\VoterImport;
use App\Support\Imports\ColumnMapper;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of docs/api/voter-imports/PATCH-voter-imports-{import}.md: every fault is `mapping: invalid`.
 * It is judged by the controller through `merged()`, after the state checks (the contract's order:
 * 404, 409, then 422), so there is no validation here that would run first.
 */
class UpdateImportMappingRequest extends FormRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [];
    }

    /**
     * The mapping as it would be after the change, or null when the body is not acceptable.
     *
     * @return array<string, int|null>|null
     */
    public function merged(): ?array
    {
        $import = $this->route('import');
        $sent = $this->input('mapping');

        if (! $import instanceof VoterImport || ! is_array($sent) || $sent === []) {
            return null;
        }

        $merged = $import->mapping ?? array_fill_keys(VoterImport::FIELDS, null);

        foreach ($sent as $field => $index) {
            if (! is_string($field) || ! in_array($field, VoterImport::FIELDS, true) || ($index !== null && ! is_int($index))) {
                return null;
            }

            $merged[$field] = $index;
        }

        $mapping = [];

        foreach (VoterImport::FIELDS as $field) {
            $mapping[$field] = $merged[$field] ?? null;
        }

        return (new ColumnMapper)->isValid($mapping, count($import->columns ?? [])) ? $mapping : null;
    }
}
