<?php

namespace App\Support\Platform;

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

        if (! is_int($id) || ! is_string($uuid) || ! is_string($name)) {
            return null;
        }

        return ['id' => $id, 'uuid' => $uuid, 'name' => $name];
    }

    public static function isActing(?Request $request = null): bool
    {
        return self::by($request) !== null;
    }
}
