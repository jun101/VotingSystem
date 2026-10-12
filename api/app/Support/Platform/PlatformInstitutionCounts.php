<?php

namespace App\Support\Platform;

use App\Enums\ElectionStatus;
use App\Models\Election;
use App\Models\Institution;
use App\Models\User;
use App\Models\Voter;
use Illuminate\Support\Carbon;

/**
 * The only numbers the platform admin sees about an institution (FR-ADM-02): users, voters, elections by status
 * and the latest sign-in. Aggregates only, read for a page of institutions in four queries; no row of a tenant is
 * loaded. Stateless.
 */
final class PlatformInstitutionCounts
{
    /**
     * Sets `platform_counts` and `platform_last_sign_in` on each institution.
     *
     * @param  iterable<Institution>  $institutions
     */
    public static function attach(iterable $institutions): void
    {
        $keys = [];

        foreach ($institutions as $institution) {
            $keys[] = self::number($institution->getKey());
        }

        if ($keys === []) {
            return;
        }

        $users = [];
        $voters = [];
        $byStatus = [];

        // The platform admin has no institution: the tenant scope would match nothing. These are aggregates over
        // the institutions of the page, grouped by institution, with no row of any of them read.
        foreach (User::withoutInstitutionScope()->whereIn('institution_id', $keys)
            ->selectRaw('institution_id, COUNT(*) as total, MAX(last_login_at) as last_login')
            ->groupBy('institution_id')->toBase()->get() as $row) {
            $users[self::number($row->institution_id)] = $row;
        }

        // The platform admin has no institution to scope by: counts only, per institution of the page.
        foreach (Voter::withoutInstitutionScope()->whereIn('institution_id', $keys)
            ->selectRaw('institution_id, COUNT(*) as total')
            ->groupBy('institution_id')->toBase()->get() as $row) {
            $voters[self::number($row->institution_id)] = self::number($row->total);
        }

        // The platform admin has no institution to scope by: counts per status only, a title is never read.
        foreach (Election::withoutInstitutionScope()->whereIn('institution_id', $keys)
            ->selectRaw('institution_id, status, COUNT(*) as total')
            ->groupBy('institution_id', 'status')->toBase()->get() as $row) {
            $byStatus[self::number($row->institution_id)][is_string($row->status) ? $row->status : ''] = self::number($row->total);
        }

        foreach ($institutions as $institution) {
            $key = self::number($institution->getKey());
            $statuses = [];

            foreach (ElectionStatus::values() as $status) {
                $statuses[$status] = $byStatus[$key][$status] ?? 0;
            }

            $last = isset($users[$key]) ? $users[$key]->last_login : null;

            $institution->setAttribute('platform_counts', [
                'users' => isset($users[$key]) ? self::number($users[$key]->total) : 0,
                'voters' => $voters[$key] ?? 0,
                'elections' => $statuses,
            ]);
            $institution->setAttribute('platform_last_sign_in', is_string($last) && $last !== ''
                ? Carbon::parse($last, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z')
                : null);
        }
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
