<?php

namespace App\Actions\Platform;

use App\Enums\Role;
use App\Exceptions\ApiException;
use App\Models\Institution;
use App\Models\User;
use App\Notifications\InstitutionReactivatedNotification;
use App\Notifications\InstitutionSuspendedNotification;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Suspends or reactivates an institution (docs/api/platform/): the change and its audit entry are one
 * transaction, the row is locked and judged again under the lock, and every owner is emailed once it is committed.
 * Stateless (the application stays in memory).
 */
final class ChangeInstitutionSuspension
{
    /** @throws ApiException 409 `already_suspended` */
    public function suspend(Institution $institution, string $reason): Institution
    {
        DB::transaction(function () use ($institution, $reason): void {
            $current = $this->locked($institution);

            if ($current->suspended_at !== null) {
                throw new ApiException(409, 'already_suspended');
            }

            $current->suspended_at = Carbon::now('UTC');
            $current->suspension_reason = $reason;
            $current->save();

            AuditLog::record('institution.suspended', $current, ['reason' => $reason]);

            $institution->setRawAttributes($current->getAttributes(), true);
        });

        $this->tell($institution, new InstitutionSuspendedNotification($reason));
        Log::info('platform.institution_suspended', ['institution' => $institution->uuid]);

        return $institution;
    }

    /** @throws ApiException 409 `not_suspended` */
    public function reactivate(Institution $institution): Institution
    {
        DB::transaction(function () use ($institution): void {
            $current = $this->locked($institution);

            if ($current->suspended_at === null) {
                throw new ApiException(409, 'not_suspended');
            }

            $lifted = (string) $current->suspension_reason;
            $current->suspended_at = null;
            $current->suspension_reason = null;
            $current->save();

            AuditLog::record('institution.reactivated', $current, ['reason' => $lifted]);

            $institution->setRawAttributes($current->getAttributes(), true);
        });

        $this->tell($institution, new InstitutionReactivatedNotification);
        Log::info('platform.institution_reactivated', ['institution' => $institution->uuid]);

        return $institution;
    }

    private function locked(Institution $institution): Institution
    {
        // The institution is the tenant itself: it is not a scoped model.
        return Institution::query()->whereKey($institution->getKey())->lockForUpdate()->firstOrFail();
    }

    /** Every owner (not the managers), in the institution's language, once the change is committed. */
    private function tell(Institution $institution, InstitutionSuspendedNotification|InstitutionReactivatedNotification $notification): void
    {
        // The signed-in user is a platform admin with no institution: the tenant scope would match nobody. The
        // owners are those of the institution named by the route, found by its key.
        $owners = User::withoutInstitutionScope()
            ->where('institution_id', $institution->getKey())
            ->where('role', Role::Owner->value)
            ->orderBy('id')
            ->get();

        foreach ($owners as $owner) {
            Notification::send($owner, $notification->locale($institution->language));
        }
    }
}
