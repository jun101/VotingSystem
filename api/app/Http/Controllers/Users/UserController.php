<?php

namespace App\Http\Controllers\Users;

use App\Actions\Auth\ClearTwoFactor;
use App\Actions\Auth\ConfirmOwnPassword;
use App\Actions\Auth\VerifySecondFactor;
use App\Actions\Users\CreateTeamMember;
use App\Actions\Users\RemoveUser;
use App\Actions\Users\ResetTeamMemberPassword;
use App\Enums\Role;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\ListRequest;
use App\Http\Requests\Users\ResetTwoFactorRequest;
use App\Http\Requests\Users\ResetUserPasswordRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Resources\PageOf;
use App\Http\Resources\TeamMemberResource;
use App\Models\User;
use App\Support\Platform\Impersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    /**
     * The users of the institution.
     *
     * Owners first, then managers, each by name (case and accents ignored), then by email. A
     * removed user is not listed. Owner only.
     *
     * @response array{data: list<array{id: string, name: string, email: string, role: 'owner'|'manager', email_verified: bool, last_login_at: string|null, two_factor_enabled: bool, is_you: bool}>, meta: array{page: int, per_page: int, total: int}}
     */
    public function index(ListRequest $request): PageOf
    {
        // The tenant scope limits the list to the signed-in owner's institution.
        $page = User::query()
            ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [Role::Owner->value])
            ->orderBy('name')
            ->orderBy('email')
            ->orderBy('id')
            ->paginate($request->perPage(), page: $request->page());

        return PageOf::from($page, TeamMemberResource::class);
    }

    /**
     * Remove a user.
     *
     * The user can no longer sign in and their next request is refused. Their address is freed;
     * their name is kept for the audit log. An institution keeps at least one owner. Removing
     * oneself also ends one's own session. Owner only.
     */
    public function destroy(Request $request, User $user, RemoveUser $remove): Response
    {
        $remove($user);
        Impersonation::note('user.removed', $user);

        if ($request->user()?->is($user) === true) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    /**
     * Turn off another user's two-factor authentication.
     *
     * For a person who has lost both their device and their recovery codes: the secret, the
     * recovery codes, the confirmation time and the stored period are cleared; their open
     * sessions stay open and their next sign-in asks for the password only. The owner's own
     * password is asked: a wrong one is a 422, and the fifth in 15 minutes ends the owner's
     * session (401). An owner who has two-factor on also gives a current `code` or `recovery_code`
     * (a wrong one is a 422, counted with the wrong codes at sign-in; the sixth is a 429). Not for oneself (the page "Mon compte" asks for the password). Owner only.
     * Limited to 10 requests per minute per user, shared with the own-settings routes.
     */
    public function resetTwoFactor(ResetTwoFactorRequest $request, User $user, ClearTwoFactor $clear, ConfirmOwnPassword $confirmPassword, VerifySecondFactor $verifySecondFactor): Response
    {
        $owner = $request->user();
        assert($owner instanceof User);

        $confirmPassword($request, $owner, $request->string('password')->toString());

        if ($owner->is($user)) {
            throw new ApiException(409, 'cannot_reset_self');
        }

        // An owner who has two-factor on gives a current second factor too.
        if ($owner->hasTwoFactorEnabled()) {
            $verifySecondFactor($owner, $request->input('code'), $request->input('recovery_code'));
        }

        if (! $user->hasTwoFactorEnabled()) {
            throw new ApiException(409, 'two_factor_not_enabled');
        }

        $clear($user);
        Impersonation::note('two_factor.changed', $user);

        Log::info('users.reset_two_factor', ['outcome' => 'reset']);

        return response()->noContent();
    }

    /**
     * Create a team member (school mode).
     *
     * An owner creates an owner or a manager directly: no email is sent, a one-time password is returned once and the
     * person must change it at the first sign-in. Asks for the owner's own password (and second factor). 404 in online mode.
     * Limited to 20 requests per hour per user.
     */
    #[\Dedoc\Scramble\Attributes\Response(status: 201, type: "array{data: array{id: string, name: string, email: string, role: 'owner'|'manager', email_verified: bool, last_login_at: string|null, two_factor_enabled: bool, is_you: bool}, one_time_password: string}")]
    public function store(StoreUserRequest $request, CreateTeamMember $create, ConfirmOwnPassword $confirmPassword, VerifySecondFactor $verifySecondFactor): JsonResponse
    {
        $owner = $request->user();
        assert($owner instanceof User);

        $confirmPassword($request, $owner, $request->string('password')->toString());

        if ($owner->hasTwoFactorEnabled()) {
            $verifySecondFactor($owner, $request->input('code'), $request->input('recovery_code'));
        }

        [$user, $password] = $create($owner, $request->string('name')->toString(), $request->string('email')->toString(), Role::from($request->string('role')->toString()));
        Impersonation::note('user.created', $user);

        return (new TeamMemberResource($user))->additional(['one_time_password' => $password])->response()->setStatusCode(201);
    }

    /**
     * Give a team member a new one-time password (school mode).
     *
     * Their sessions end and they must change the password at the next sign-in. Not for oneself. Asks for the owner's own
     * password (and second factor). 404 in online mode. Limited to 10 requests per minute per user.
     *
     * @response array{one_time_password: string}
     */
    public function passwordReset(ResetUserPasswordRequest $request, User $user, ResetTeamMemberPassword $reset, ConfirmOwnPassword $confirmPassword, VerifySecondFactor $verifySecondFactor): JsonResponse
    {
        $owner = $request->user();
        assert($owner instanceof User);

        $confirmPassword($request, $owner, $request->string('password')->toString());

        if ($owner->is($user)) {
            throw new ApiException(409, 'cannot_reset_self', messageKey: 'cannot_reset_own_password');
        }

        if ($owner->hasTwoFactorEnabled()) {
            $verifySecondFactor($owner, $request->input('code'), $request->input('recovery_code'));
        }

        $password = $reset($user);
        Impersonation::note('user.password_reset', $user);

        return response()->json(['one_time_password' => $password]);
    }
}
