<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

/**
 * Public identity of a model: a random version 4 UUID in the `uuid` column.
 *
 * - the UUID is filled on creation (version 4, never time-ordered: a time-ordered id
 *   would reveal when a record was created);
 * - it is the route key, so a URL never holds the numeric key;
 * - the numeric `id` is hidden from any array or JSON form of the model.
 *
 * @mixin Model
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            if (blank($model->getAttribute('uuid'))) {
                $model->setAttribute('uuid', Uuid::uuid4()->toString());
            }
        });
    }

    public function initializeHasUuid(): void
    {
        $this->makeHidden($this->getKeyName());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
