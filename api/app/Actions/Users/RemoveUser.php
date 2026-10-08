<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\LinkTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Removes a user from the signed-in owner's institution (docs/api/users/DELETE-users-{user}.md).
 *
 * The row stays (`deleted_at`, the name is kept for the audit log); the address is rewritten
 * so that it is free again; the pending email and password links are deleted. The last-owner
 * rule is checked in the same transaction, with the owners' rows locked: two owners removing
 * each other cannot both succeed.
 */
final class RemoveUser
{
    public function __invoke(User $target): void
    {
        DB::transaction(function () use ($target): void {
            // The tenant scope limits both reads to the institution of the signed-in owner.
            $owners = User::query()->where('role', Role::Owner->value)->lockForUpdate()->pluck('uuid');
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            if ($locked->role === Role::Owner && $owners->count() <= 1) {
                Log::info('users.remove', ['outcome' => 'last_owner']);

                throw new ApiException(409, 'last_owner');
            }

            DB::table(LinkTokens::VERIFICATION)->where('user_id', $locked->getKey())->delete();
            DB::table(LinkTokens::RESET)->where('user_id', $locked->getKey())->delete();

            $locked->email = "removed-{$locked->uuid}@removed.invalid";
            $locked->save();
            $locked->delete();
        });

        Log::info('users.remove', ['outcome' => 'removed']);
    }
}
