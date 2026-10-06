<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Collection;
use JsonSerializable;

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
            if (is_string($name) && self::isIdentifierName($name)) {
                continue;
            }

            $kept[$name] = $this->plain($value);
        }

        return $kept;
    }

    /**
     * What a field holds, as plain data with no identifier in it. A model, a collection,
     * anything convertible to an array is converted first, then filtered like the rest.
     * A nested `ApiResource` is left as it is: it applies this same rule to itself, and
     * its `id` is a UUID.
     */
    private function plain(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => $this->withoutIdentifiers($value),
            $value instanceof self => $value,
            $value instanceof ResourceCollection => ($value->collection ?? new Collection)->map($this->plain(...))->all(),
            $value instanceof JsonResource => $this->withoutIdentifiers($value->resolve()),
            $value instanceof Arrayable => $this->withoutIdentifiers($value->toArray()),
            $value instanceof JsonSerializable => $this->plain($value->jsonSerialize()),
            default => $value,
        };
    }

    /** `id`, `election_id`, `voter_ids`, and the camel-case `electionId`, `voterIds`, `electionID`. */
    private static function isIdentifierName(string $name): bool
    {
        return preg_match('/^id$|_ids?$/i', $name) === 1
            || preg_match('/[a-z0-9](Ids?|IDs?)$/', $name) === 1;
    }
}
