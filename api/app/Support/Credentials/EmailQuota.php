<?php

namespace App\Support\Credentials;

use App\Models\Credential;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * The emails of access codes an institution may have queued or sent in 24 hours. Counted from the
 * `credentials` rows themselves (status `queued` or `sent`, issued in the last 24 hours), so nothing
 * extra is stored and a code written without email stops counting. Tenant-safe: the institution is
 * named by the caller from the election.
 */
final class EmailQuota
{
    /** The number of emails the institution can still queue now, never negative. */
    public static function left(int $institutionId): int
    {
        $limit = max(0, Config::integer('credentials.emails_per_institution_per_day', 5000));

        // The caller names the institution (the election's own): the scope would read the signed-in user's.
        $used = Credential::withoutInstitutionScope()
            ->where('institution_id', $institutionId)
            ->whereIn('email_status', [Credential::EMAIL_QUEUED, Credential::EMAIL_SENT])
            ->where('issued_at', '>=', Carbon::now('UTC')->subDay())
            ->count();

        return max(0, $limit - $used);
    }
}
