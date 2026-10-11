<?php

namespace App\Support\Audit;

use App\Http\Requests\Audit\AuditFilterRequest;
use App\Models\AuditEntry;
use App\Models\Election;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The filters of the audit list and export (docs/api/audit/GET-audit.md). A filter that names nothing that exists
 * (an unknown election, another institution's, a value that is not a uuid) matches no row, like an unknown action.
 * The tenant scope of `AuditEntry` limits every query to the signed-in institution, whatever is asked.
 */
final class AuditFilters
{
    /**
     * @param  Builder<AuditEntry>  $query
     * @return Builder<AuditEntry>
     */
    public static function apply(Builder $query, AuditFilterRequest $request): Builder
    {
        $action = $request->action();

        if ($action !== null) {
            if (str_ends_with($action, '.')) {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $action);
                $query->where('audit_entries.action', 'like', $escaped.'%');
            } else {
                $query->where('audit_entries.action', $action);
            }
        }

        $election = $request->election();

        if ($election !== null) {
            // Through the tenant scope: another institution's election finds nothing.
            $key = Str::isUuid($election) ? Election::query()->where('uuid', strtolower($election))->value('id') : null;
            $key === null ? $query->whereRaw('0 = 1') : $query->where('audit_entries.election_id', $key);
        }

        $user = $request->actor();

        if ($user === 'system') {
            // The scheduler: no user and the name `system` (an entry whose user was removed keeps its name).
            $query->whereNull('audit_entries.user_id')->where('audit_entries.actor_name', 'system');
        } elseif ($user !== null) {
            // A removed user is still named in the log, so the soft-deleted rows are looked at too.
            $key = Str::isUuid($user) ? User::withTrashed()->where('uuid', strtolower($user))->value('id') : null;
            $key === null ? $query->whereRaw('0 = 1') : $query->where('audit_entries.user_id', $key);
        }

        $from = $request->from();

        if ($from !== null) {
            $query->where('audit_entries.created_at', '>=', $from.' 00:00:00');
        }

        $to = $request->to();

        if ($to !== null) {
            $query->where('audit_entries.created_at', '<', Carbon::parse($to, 'UTC')->addDay()->format('Y-m-d H:i:s'));
        }

        return $query;
    }
}
