<?php

namespace App\Support\Audit;

use App\Models\AuditEntry;
use App\Models\Election;
use App\Models\GeneratedFile;
use App\Models\Institution;
use App\Models\User;
use App\Models\Voter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The one writer of the audit log (docs/slices/17-audit-log.md, FR-AUD-01, FR-AUD-02).
 *
 * Called once at the end of a successful action, inside its transaction where it has one, so a refused or
 * failed request leaves nothing. The actor (uuid and name) and the client address are copied at write time.
 * `$details` never holds a code, a hash, a password, a token, a vote, a choice or a file's content: the
 * callers name what goes in.
 *
 * Stateless (the application stays in memory): the signed-in user and the request are read on each call.
 */
final class AuditLog
{
    /** The `subject_type` of the models that can be a subject. */
    private const SUBJECT_TYPES = [
        Election::class => 'election',
        Voter::class => 'voter',
        GeneratedFile::class => 'generated_file',
        User::class => 'user',
        Institution::class => 'institution',
    ];

    /**
     * @param  string  $action  the code (`election.closed`)
     * @param  Model|null  $subject  what the action was done to; its uuid is copied, so a deleted record is still named
     * @param  array<string, mixed>  $details
     * @param  Election|null  $election  the election the entry belongs to; taken from the subject when it is one
     * @param  bool  $system  the scheduler: no user, no address
     */
    public static function record(string $action, ?Model $subject = null, array $details = [], ?Election $election = null, bool $system = false): void
    {
        $actor = $system ? null : Auth::guard()->user();
        $actor = $actor instanceof User ? $actor : null;

        $electionKey = $election?->getKey();
        $institution = $election?->institution_id;

        // The platform's own actions (an institution suspended or reactivated) belong to nobody's log: no institution,
        // so no owner's list ever finds them; the subject is the institution, named by its uuid.
        $platform = $subject instanceof Institution;

        if ($subject !== null && ! $platform) {
            $electionKey ??= match (true) {
                $subject instanceof Election => $subject->exists ? $subject->getKey() : null,
                $subject instanceof Voter, $subject instanceof GeneratedFile => $subject->election_id,
                default => null,
            };
            $institution ??= $subject->getAttribute('institution_id');
        }

        if ($platform) {
            $institution = null;
        } else {
            $institution ??= $actor?->institution_id;
        }

        $entry = new AuditEntry;
        $entry->institution_id = is_numeric($institution) ? (int) $institution : null;
        $entry->user_id = $actor?->id;
        $entry->actor_name = $actor !== null ? mb_substr($actor->name, 0, 150) : 'system';
        $entry->action = $action;
        $entry->election_id = is_numeric($electionKey) ? (int) $electionKey : null;
        $entry->subject_type = $subject === null ? null : (self::SUBJECT_TYPES[$subject::class] ?? null);
        $uuid = $subject?->getAttribute('uuid');
        $entry->subject_uuid = is_string($uuid) ? $uuid : null;
        $entry->details = $details;
        $entry->ip_address = $system ? null : self::address();
        $entry->created_at = Carbon::now('UTC');
        $entry->save();
    }

    /** The client address as the API sees it (the trusted proxy rules are applied by the request). */
    private static function address(): ?string
    {
        $ip = request()->ip();

        return is_string($ip) && $ip !== '' ? $ip : null;
    }
}
