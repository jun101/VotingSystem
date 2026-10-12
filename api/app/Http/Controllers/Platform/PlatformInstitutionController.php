<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\ChangeInstitutionSuspension;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ListInstitutionsRequest;
use App\Http\Resources\PageOf;
use App\Http\Resources\PlatformInstitutionResource;
use App\Models\Institution;
use App\Support\Platform\PlatformInstitutionCounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PlatformInstitutionController extends Controller
{
    /**
     * The institutions of the platform.
     *
     * Platform admin only. Newest created first, 20 per page (1 to 100), filtered by part of the name (`q`) and by
     * `status` (`active` or `suspended`). Counts only: users, voters and elections by status, and the latest
     * sign-in. Never a name or email of a user or a voter, a code, a candidate, a title or a vote.
     *
     * @response array{data: list<array{id: string, name: string, type: 'school'|'university'|'association'|'other', status: 'active'|'suspended', suspended_at: string|null, suspension_reason: string|null, created_at: string|null, last_sign_in_at: string|null, counts: array{users: int, voters: int, elections: array{draft: int, scheduled: int, open: int, closed: int, published: int, archived: int}}}>, meta: array{page: int, per_page: int, total: int}}
     */
    public function index(ListInstitutionsRequest $request): PageOf
    {
        // The institution is the tenant itself: it is not a scoped model.
        $query = Institution::query();

        if (($term = $request->term()) !== null) {
            $query->where('name', 'like', '%'.addcslashes($term, '\\%_').'%');
        }

        if (($status = $request->status()) !== null) {
            $status === 'suspended' ? $query->whereNotNull('suspended_at') : $query->whereNull('suspended_at');
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        PlatformInstitutionCounts::attach($page->items());

        return PageOf::from($page, PlatformInstitutionResource::class);
    }

    /**
     * Suspend an institution.
     *
     * Platform admin only; the reason (3 to 500 characters, control characters removed) is shown to the owners at
     * sign-in and emailed to them. Open elections are paused; nothing is deleted. 409 `already_suspended`. Limited
     * to 60 requests an hour per admin, shared with the reactivation.
     *
     * @response array{data: array{id: string, name: string, type: 'school'|'university'|'association'|'other', status: 'active'|'suspended', suspended_at: string|null, suspension_reason: string|null, created_at: string|null, last_sign_in_at: string|null, counts: array{users: int, voters: int, elections: array{draft: int, scheduled: int, open: int, closed: int, published: int, archived: int}}}}
     */
    public function suspend(Request $request, Institution $institution, ChangeInstitutionSuspension $change): PlatformInstitutionResource
    {
        // Judged after the institution is bound, so by hand rather than by a form request.
        $reason = $this->cleanReason($request->input('reason'));
        Validator::make(['reason' => $reason], ['reason' => ['bail', 'required', 'string', 'min:3', 'max:500']])->validate();

        return $this->shown($change->suspend($institution, (string) $reason));
    }

    /**
     * Reactivate an institution.
     *
     * Platform admin only. Clears the suspension and its reason; its owners are emailed (without the reason).
     * 409 `not_suspended`. Limited to 60 requests an hour per admin, shared with the suspension.
     *
     * @response array{data: array{id: string, name: string, type: 'school'|'university'|'association'|'other', status: 'active'|'suspended', suspended_at: string|null, suspension_reason: string|null, created_at: string|null, last_sign_in_at: string|null, counts: array{users: int, voters: int, elections: array{draft: int, scheduled: int, open: int, closed: int, published: int, archived: int}}}}
     */
    public function reactivate(Institution $institution, ChangeInstitutionSuspension $change): PlatformInstitutionResource
    {
        // `{institution}` is bound by uuid. `Institution` is the tenant itself and carries no tenant scope: a
        // platform admin has no institution to scope by, and a value that is not a UUID or is unknown answers 404.
        return $this->shown($change->reactivate($institution));
    }

    /** Trimmed, control and format characters removed (a bell, a right-to-left override); null when not a string. */
    private function cleanReason(mixed $value): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $text = preg_replace(['/[\r\n\t]+/u', '/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u'], [' ', ''], $value);

        return trim($text ?? '');
    }

    private function shown(Institution $institution): PlatformInstitutionResource
    {
        PlatformInstitutionCounts::attach([$institution]);

        return new PlatformInstitutionResource($institution);
    }
}
