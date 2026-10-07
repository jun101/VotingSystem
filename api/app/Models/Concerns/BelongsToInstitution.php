<?php

namespace App\Models\Concerns;

use App\Models\Scopes\InstitutionScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * The one trait of every tenant model (docs/design/architecture.md section 4.4).
 *
 * - Every query is limited to the signed-in user's institution, relations included, and
 *   matches nothing when nobody (or a platform admin) is signed in.
 * - A new record takes the signed-in user's institution. When nobody can own it, saving it
 *   throws. Code that sets the institution itself (sign-up) keeps it; a request body
 *   never can, since the column is not fillable.
 * - A saved record never changes institution: trying to is a `LogicException`.
 * - A route parameter that is not a UUID finds nothing, like a UUID that does not exist.
 * - The scope is removed by one static method, `withoutInstitutionScope()`, and every call
 *   carries a comment saying why. Nothing else removes it (a test reads the source).
 *
 * @mixin Model
 */
trait BelongsToInstitution
{
    public static function bootBelongsToInstitution(): void
    {
        static::addGlobalScope(new InstitutionScope);

        static::creating(function (Model $model): void {
            $current = InstitutionScope::currentInstitutionKey();
            $named = $model->getAttribute('institution_id');

            if ($named === null && $current !== null) {
                $model->setAttribute('institution_id', $current);
            }

            if ($model->getAttribute('institution_id') === null && ! self::mayHaveNoInstitution($model)) {
                throw new LogicException('A tenant record needs an institution and nobody is signed in to give one.');
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('institution_id')) {
                throw new LogicException('A saved record cannot move to another institution.');
            }
        });
    }

    /**
     * A query on this model without the institution scope. Every call site says why, in a
     * comment on the lines directly above it.
     *
     * @return Builder<static>
     */
    public static function withoutInstitutionScope(): Builder
    {
        return static::query()->withoutGlobalScope(InstitutionScope::class);
    }

    /** Whether a record may exist with no institution (the platform admin, on `User`). */
    protected static function mayHaveNoInstitution(Model $model): bool
    {
        return method_exists($model, 'allowsNoInstitution') && $model->allowsNoInstitution() === true;
    }

    /**
     * Binds a route parameter through the scope: another institution's UUID, an unknown one
     * and a value that is not a UUID all find nothing, so the answer is the same 404.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $field ??= $this->getRouteKeyName();

        if ($field === 'uuid' && ! (is_string($value) && Str::isUuid($value))) {
            return null;
        }

        return $this->resolveRouteBindingQuery($this, $value, $field)->first();
    }
}
