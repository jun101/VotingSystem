<?php

namespace App\Actions\Platform;

use App\Enums\Role;
use App\Exceptions\ApiException;
use App\Models\Institution;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Platform\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The platform admin acts as an institution's oldest active owner, and stops (docs/api/platform/, slice 18c).
 *
 * The guard's user becomes the owner and the real admin is kept in the session, so every rule of an owner applies
 * unchanged. The session id is regenerated at both ends. No `last_login_at`, no email: the audit entries are the
 * safeguard. Stateless (the application stays in memory).
 */
final class ImpersonateInstitution
{
    /**
     * @throws ApiException 409 `already_impersonating`, 403 `institution_suspended`, 404 `not_found`
     */
    public function start(Request $request, User $admin, Institution $institution): User
    {
        if (Impersonation::isActing($request)) {
            throw new ApiException(409, 'already_impersonating');
        }

        if ($institution->isSuspended()) {
            throw new ApiException(403, 'institution_suspended');
        }

        // The admin has no institution, so the tenant scope would match nobody: the owner is found by the institution's key.
        $owner = User::withoutInstitutionScope()
            ->where('institution_id', $institution->getKey())
            ->where('role', Role::Owner->value)
            ->orderBy('id')
            ->first();

        if ($owner === null) {
            throw new ApiException(404, 'not_found');
        }

        $session = $request->session();

        // Signs the owner in (the session id is regenerated and the password hash follows the owner).
        Auth::guard()->login($owner);
        $session->put([
            Impersonation::KEY_ID => $admin->id,
            Impersonation::KEY_UUID => $admin->uuid,
            Impersonation::KEY_NAME => $admin->name,
        ]);

        try {
            AuditLog::record('platform.impersonation_started', $institution, ['by' => $admin->name], institution: $institution);
        } catch (\Throwable $e) {
            // No entry, no session as the owner.
            $this->giveBack($request, $admin);

            throw $e;
        }

        $session->regenerateToken();
        Log::info('platform.impersonation_started', ['institution' => $institution->uuid]);

        return $owner;
    }

    /** @throws ApiException 409 `not_impersonating` */
    public function end(Request $request): User
    {
        $by = Impersonation::by($request);
        $owner = $request->user();

        if ($by === null || ! $owner instanceof User) {
            throw new ApiException(409, 'not_impersonating');
        }

        // The admin has no institution, so the tenant scope would match nobody; the session names the admin's key.
        $admin = User::withoutInstitutionScope()->whereKey($by['id'])->where('role', Role::PlatformAdmin->value)->first();

        if ($admin === null) {
            // The admin is gone: nobody stays signed in.
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new ApiException(401, 'unauthenticated');
        }

        DB::transaction(function () use ($owner, $by): void {
            // Still the owner's session, so the entry is the admin's act, named and visible in the owner's list.
            $institution = $owner->institution;
            AuditLog::record('platform.impersonation_ended', $institution, ['by' => $by['name']], institution: $institution);
        });

        $this->giveBack($request, $admin);
        $request->session()->regenerateToken();
        Log::info('platform.impersonation_ended', ['institution' => $owner->institution?->uuid]);

        return $admin;
    }

    private function giveBack(Request $request, User $admin): void
    {
        $request->session()->forget([Impersonation::KEY_ID, Impersonation::KEY_UUID, Impersonation::KEY_NAME]);
        Auth::guard()->login($admin);
    }
}
