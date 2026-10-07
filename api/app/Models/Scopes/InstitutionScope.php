<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * The tenant scope (docs/design/architecture.md section 4.4): every query of a tenant model
 * is limited to the institution of the signed-in user.
 *
 * It fails closed: with nobody signed in, or a platform admin (no institution), it matches
 * no row. The institution is read from the guard each time, never remembered: the
 * application stays in memory between requests (Octane), so this class holds no state.
 */
/** @implements Scope<Model> */
final class InstitutionScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $institution = self::currentInstitutionKey();

        if ($institution === null) {
            $builder->whereRaw('0 = 1');

            return;
        }

        $builder->where($model->qualifyColumn('institution_id'), $institution);
    }

    /** The numeric key of the signed-in user's institution, or null when there is none. */
    public static function currentInstitutionKey(): ?int
    {
        $user = Auth::guard()->user();

        if (! $user instanceof User || $user->institution_id === null) {
            return null;
        }

        return (int) $user->institution_id;
    }
}
