<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\ImpersonateInstitution;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    /**
     * Act as an institution's owner.
     *
     * Platform admin only. The session becomes the institution's oldest owner's (every rule of an owner applies,
     * ballot secrecy included) and stays so until `DELETE /platform/impersonation` or sign-out; no time limit, nothing
     * refused, no email to the owners. Every entry written meanwhile names the admin. 409 `already_impersonating`,
     * 403 `institution_suspended`. Limited to 60 requests an hour per admin, shared with suspend and reactivate.
     *
     * @response array{data: array{id: string, name: string, email: string, role: 'owner'|'manager'|'platform_admin', email_verified: bool, language: 'fr'|'en', institution: array{id: string, name: string, type: 'school'|'university'|'association'|'other'}|null, impersonating: array{by: string}}}
     */
    public function store(Request $request, Institution $institution, ImpersonateInstitution $impersonate): UserResource
    {
        // `{institution}` is bound by uuid; `Institution` is the tenant itself and carries no tenant scope.
        $admin = $this->realAdmin($request);

        return new UserResource($impersonate->start($request, $admin, $institution));
    }

    /**
     * Stop acting as the owner.
     *
     * The platform admin gets their own session back (the session id is regenerated). 409 `not_impersonating`.
     * Works even if the institution was suspended meanwhile. Not rate limited: ending must always work.
     *
     * @response array{data: array{id: string, name: string, email: string, role: 'owner'|'manager'|'platform_admin', email_verified: bool, language: 'fr'|'en', institution: array{id: string, name: string, type: 'school'|'university'|'association'|'other'}|null}}
     */
    public function destroy(Request $request, ImpersonateInstitution $impersonate): UserResource
    {
        return new UserResource($impersonate->end($request));
    }

    /** The admin: the session's user, or the admin behind the owner's session (the middleware has judged the role). */
    private function realAdmin(Request $request): User
    {
        $user = $request->user();
        \assert($user instanceof User);

        return $user;
    }
}
