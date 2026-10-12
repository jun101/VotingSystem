<?php

namespace App\Support\Platform;

use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Who is behind the session when a platform admin acts as an institution's owner (slice 18c).
 *
 * The guard's user is the owner, so every policy and the tenant scope apply unchanged; the real admin is kept in
 * the session (server side, in Redis) and read here. Stateless: nothing is kept between requests (Octane), the
 * request is read on each call. No request, no session (the scheduler, a queued job) means nobody is acting.
 */
final class Impersonation
{
    public const KEY_ID = 'impersonator_id';

    public const KEY_UUID = 'impersonator_uuid';

    public const KEY_NAME = 'impersonator_name';

    /** The key of the owner the admin acts as: the session counts as acting only while the signed-in user is this one. */
    public const KEY_OWNER = 'impersonated_owner_id';

    /** A fingerprint of the admin's password hash at the start: the session ends when it no longer matches. */
    public const KEY_FINGERPRINT = 'impersonator_pw';

    public const KEYS = [self::KEY_ID, self::KEY_UUID, self::KEY_NAME, self::KEY_OWNER, self::KEY_FINGERPRINT];

    /** A hash of the admin's password hash: it changes when the password does. */
    public static function fingerprint(User $admin): string
    {
        return hash('sha256', 'impersonation|'.$admin->password);
    }

    /** Drops the acting state from the session of the request (a sign-in, a registration, an invitation accepted). */
    public static function clear(?Request $request = null): void
    {
        $request ??= request();

        if ($request->hasSession()) {
            $request->session()->forget(self::KEYS);
        }
    }

    /**
     * One entry for an owner action that writes none of its own, while acting (no effect otherwise).
     *
     * @param  'user.removed'|'invitation.created'|'institution.updated'|'institution.logo_changed'|'two_factor.changed'  $what
     */
    public static function note(string $what, ?Model $subject = null): void
    {
        if (self::isActing()) {
            AuditLog::record('platform.impersonation_action', $subject, ['what' => $what]);
        }
    }

    /** @return array{id: int, uuid: string, name: string}|null the admin behind the session, or null when nobody is acting */
    public static function by(?Request $request = null): ?array
    {
        $request ??= request();

        if (! $request->hasSession()) {
            return null;
        }

        $session = $request->session();
        $id = $session->get(self::KEY_ID);
        $uuid = $session->get(self::KEY_UUID);
        $name = $session->get(self::KEY_NAME);
        $owner = $session->get(self::KEY_OWNER);

        if (! is_int($id) || ! is_string($uuid) || ! is_string($name) || ! is_int($owner)) {
            return null;
        }

        // Acting only while the signed-in user is the owner the admin started as.
        $user = $request->user();

        if (! $user instanceof User || $user->getKey() !== $owner) {
            return null;
        }

        return ['id' => $id, 'uuid' => $uuid, 'name' => $name];
    }

    public static function isActing(?Request $request = null): bool
    {
        return self::by($request) !== null;
    }
}
