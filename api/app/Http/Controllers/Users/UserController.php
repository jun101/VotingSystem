<?php

namespace App\Http\Controllers\Users;

use App\Actions\Auth\ClearTwoFactor;
use App\Actions\Auth\ConfirmOwnPassword;
use App\Actions\Users\RemoveUser;
use App\Enums\Role;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordRequest;
use App\Http\Requests\Users\ListRequest;
use App\Http\Resources\PageOf;
use App\Http\Resources\TeamMemberResource;
use App\Models\User;
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
     * session (401). Not for oneself (the page "Mon compte" asks for the password). Owner only.
     * Limited to 10 requests per minute per user, shared with the own-settings routes.
     */
    public function resetTwoFactor(PasswordRequest $request, User $user, ClearTwoFactor $clear, ConfirmOwnPassword $confirmPassword): Response
    {
        $owner = $request->user();
        assert($owner instanceof User);

        $confirmPassword($request, $owner, $request->string('password')->toString());

        if ($owner->is($user)) {
            throw new ApiException(409, 'cannot_reset_self');
        }

        if (! $user->hasTwoFactorEnabled()) {
            throw new ApiException(409, 'two_factor_not_enabled');
        }

        $clear($user);

        Log::info('users.reset_two_factor', ['outcome' => 'reset']);

        return response()->noContent();
    }
}
