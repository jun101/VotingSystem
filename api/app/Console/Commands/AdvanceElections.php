<?php

namespace App\Console\Commands;

use App\Enums\ElectionStatus;
use App\Models\Election;
use App\Models\Institution;
use App\Support\Audit\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Opens and closes elections by time (FR-ELEC-03, docs/slices/12-voting-flow.md "Opening and
 * closing"): a `scheduled` election whose `starts_at` has passed becomes `open` (`opened_at`), an
 * `open` one whose `ends_at` has passed becomes `closed` (`closed_at`). Drafts, closed, published
 * and archived elections are never touched.
 *
 * It runs every minute and can run twice in a row: the second run finds nothing to do. Each
 * election is read again under its row lock before it is changed, so a run racing a vote, an
 * unschedule or another run judges the state as it is. The vote itself does not wait for this
 * command: it checks the server time (FR-ELEC-05). It prints counts only.
 */
class AdvanceElections extends Command
{
    protected $signature = 'elections:advance';

    protected $description = 'Open the scheduled elections whose start has passed and close the open ones whose end has passed';

    public function handle(): int
    {
        $now = Carbon::now('UTC');
        $opened = 0;
        $closed = 0;

        // No user is signed in: the command works across every institution, on the status and the dates only.
        // The elections of a suspended institution are paused: not opened, not closed (docs/slices/18).
        $due = Election::withoutInstitutionScope()
            ->whereNotIn('institution_id', Institution::query()->whereNotNull('suspended_at')->select('id'))
            ->where(function ($query) use ($now): void {
                $query->where(function ($query) use ($now): void {
                    $query->where('status', ElectionStatus::Scheduled->value)->where('starts_at', '<=', $now->format('Y-m-d H:i:s'));
                })->orWhere(function ($query) use ($now): void {
                    $query->where('status', ElectionStatus::Open->value)->where('ends_at', '<=', $now->format('Y-m-d H:i:s'));
                });
            })
            ->orderBy('id')
            ->pluck('id');

        foreach ($due as $key) {
            DB::transaction(function () use ($key, $now, &$opened, &$closed): void {
                // The election read again under its lock: something may have changed since the list was drawn.
                $election = Election::withoutInstitutionScope()->whereKey($key)->lockForUpdate()->first();

                // Suspended since the list was drawn: paused, nothing changes.
                if ($election === null || Institution::query()->whereKey($election->institution_id)->whereNotNull('suspended_at')->exists()) {
                    return;
                }

                $changed = false;
                $events = [];

                if ($election->status === ElectionStatus::Scheduled && $election->starts_at <= $now) {
                    $election->status = ElectionStatus::Open;
                    $election->opened_at = $now;
                    $changed = true;
                    $opened++;
                    $events[] = 'election.opened';
                }

                if ($election->status === ElectionStatus::Open && $election->ends_at <= $now) {
                    $election->status = ElectionStatus::Closed;
                    $election->closed_at = $now;
                    $changed = true;
                    $closed++;
                    $events[] = 'election.closed';
                }

                if ($changed) {
                    $election->save();

                    // Written with no user and no address: `system`, `by: scheduler`.
                    foreach ($events as $event) {
                        AuditLog::record($event, $election, ['by' => 'scheduler'], system: true);
                    }
                }
            });
        }

        $this->line("opened: {$opened}, closed: {$closed}");

        return self::SUCCESS;
    }
}
