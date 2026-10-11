<?php

namespace App\Http\Controllers\Turnout;

use App\Actions\Elections\ComputeDashboard;
use App\Enums\Role;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * The dashboard.
     *
     * The institution's elections counted by status, the voters of all of them, the open elections
     * with their live turnout (at most 10, newest first; an election past its end is not listed),
     * the scheduled ones with their start, and the to-do items (`voters_not_voted`,
     * `draft_blockers`, `codes_missing`) with the election each concerns. No vote count of any
     * ballot. Owner or manager.
     *
     * @response array{data: array{counts: array{draft: int, scheduled: int, open: int, closed: int, published: int, archived: int}, voters: int, open: list<array{id: string, title: string, ends_at: string, eligible: int, voted: int, percentage: float}>, upcoming: list<array{id: string, title: string, starts_at: string}>, todo: list<array{kind: 'voters_not_voted'|'draft_blockers'|'codes_missing', election: array{id: string, title: string}, count: int}>}}
     */
    public function __invoke(Request $request, ComputeDashboard $dashboard): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $user->institution_id === null || ! in_array($user->role, [Role::Owner, Role::Manager], true)) {
            throw new ApiException(403, 'forbidden');
        }

        return response()->json(['data' => $dashboard()], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
