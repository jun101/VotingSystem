<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base of every API resource (NFR-SEC-08).
 *
 * The record's `uuid` is output as `id`. The numeric key never is, and neither is any
 * `*_id` column: such keys are dropped from what `fields()` returns, so a careless
 * resource cannot leak one. A related record is named by a field carrying its UUID, or
 * nested as another resource.
 *
 * @property Model $resource
 */
abstract class ApiResource extends JsonResource
{
    /**
     * The fields of the record, without its identifier.
     *
     * @return array<string, mixed>
     */
    abstract protected function fields(Request $request): array;

    /**
     * @return array<string, mixed>
     */
    final public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $fields */
        $fields = $this->withoutIdentifiers($this->fields($request));

        return ['id' => $this->resource->getAttribute('uuid')] + $fields;
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @return array<array-key, mixed>
     */
    private function withoutIdentifiers(array $fields): array
    {
        $kept = [];

        foreach ($fields as $name => $value) {
            if (is_string($name) && preg_match('/^id$|_ids?$/i', $name) === 1) {
                continue;
            }

            $kept[$name] = is_array($value) ? $this->withoutIdentifiers($value) : $value;
        }

        return $kept;
    }
}
