<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Plants an audit entry straight in the table (slice 17, docs/slices/17-audit-log.md): the application has no way to
 * backdate or to write for another institution. Part of the acceptance harness: it is not edited when a slice is coded.
 */
final class Audit
{
    /**
     * @param  string  $institution  the institution's uuid, or `platform` for an entry with no institution
     * @param  array{action?: string, user?: string, election?: string, actor?: string, subject_type?: string, subject_uuid?: string, details?: array, ip?: string, at?: string}  $o
     */
    public static function plant(string $institution, array $o = []): void
    {
        $db = DB::connection(useMigratorConnection());
        $user = isset($o['user']) ? $db->table('users')->where('uuid', $o['user'])->first() : null;
        $election = isset($o['election']) ? $db->table('elections')->where('uuid', $o['election'])->value('id') : null;

        $db->table('audit_entries')->insert([
            'uuid' => Uuid::uuid4()->toString(),
            'institution_id' => $institution === 'platform' ? null : $db->table('institutions')->where('uuid', $institution)->value('id'),
            'user_id' => $user?->id,
            'actor_name' => $o['actor'] ?? ($user?->name ?? 'system'),
            'action' => $o['action'] ?? 'election.updated',
            'election_id' => $election,
            'subject_type' => $o['subject_type'] ?? null,
            'subject_uuid' => $o['subject_uuid'] ?? null,
            'details' => json_encode($o['details'] ?? []),
            'ip_address' => $o['ip'] ?? '10.0.0.1',
            'created_at' => $o['at'] ?? now('UTC')->toDateTimeString(),
        ]);
    }
}
