<?php

namespace App\Http\Resources;

use App\Models\AuditEntry;
use Illuminate\Http\Request;

/**
 * One entry of the audit log (docs/api/audit/GET-audit.md). Never a numeric key: the actor, the election and the
 * subject are named by uuid. `actor.id` is null for the scheduler.
 *
 * @property AuditEntry $resource
 */
final class AuditEntryResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        $entry = $this->resource;
        $election = $entry->election;
        $user = $entry->user;

        // Plain objects, not arrays: the base resource drops every key named `id` from an array, and these ids
        // are uuids (the user's, the election's, the subject's), copied or read here, never a database key.
        return [
            'action' => $entry->action,
            'actor' => (object) ['id' => $user?->uuid, 'name' => $entry->actor_name],
            'election' => $election === null ? null : (object) ['id' => $election->uuid, 'title' => $election->title],
            'subject' => $entry->subject_type === null || $entry->subject_uuid === null
                ? null
                : (object) ['type' => $entry->subject_type, 'id' => $entry->subject_uuid],
            // An object, `{}` when empty.
            'details' => (object) ($entry->details ?? []),
            'ip_address' => $entry->ip_address,
            'created_at' => $entry->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
