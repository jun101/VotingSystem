<?php

namespace App\Http\Controllers\Users;

use App\Actions\Users\RemoveUser;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\ListRequest;
use App\Http\Resources\PageOf;
use App\Http\Resources\TeamMemberResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    /**
     * The users of the institution.
     *
     * Owners first, then managers, each by name (case and accents ignored), then by email. A
     * removed user is not listed. Owner only.
     *
     * @response array{data: list<array{id: string, name: string, email: string, role: 'owner'|'manager', email_verified: bool, last_login_at: string|null, is_you: bool}>, meta: array{page: int, per_page: int, total: int}}
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
}
