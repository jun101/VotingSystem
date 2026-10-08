<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Finds the user of a session or of a sign-in. Nobody is signed in yet at that moment, and
 * the institution scope would need the signed-in user to find the signed-in user, so this
 * provider is the one place where the guard crosses tenants.
 */
final class SessionUserProvider extends EloquentUserProvider
{
    /**
     * @param  Model|null  $model
     * @return Builder<Model>
     */
    protected function newModelQuery($model = null)
    {
        // The guard looks the user up before anyone is signed in, so the scope has no
        // institution to read: it must be removed here, and only here, for the guard.
        /** @var Builder<Model> $query */
        $query = User::withoutInstitutionScope();

        with($query, $this->queryCallback);

        return $query;
    }
}
