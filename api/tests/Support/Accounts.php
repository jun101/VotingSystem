<?php

namespace Tests\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;

/**
 * Test data and mail for the slices that use accounts (02 and after).
 *
 * It writes the rows directly, with the column names of docs/design/database.md, so a test
 * does not depend on the models or the factories. It uses the `migrator` account, which can
 * empty the tables (the application cannot).
 *
 * Part of the acceptance harness: it is not edited when a slice is coded.
 */
final class Accounts
{
    private static bool $migrated = false;

    public const PASSWORD = 'correct horse battery staple';

    /**
     * Brings the test database up to date and empties every table but `migrations`.
     * Call it in `beforeEach`.
     */
    public static function reset(): void
    {
        $connection = useMigratorConnection();

        if (! self::$migrated) {
            expect(Artisan::call('migrate', ['--database' => $connection, '--force' => true]))->toBe(0);
            expect(Artisan::call('db:grant-app', ['--database' => $connection]))->toBe(0);
            self::$migrated = true;
        }

        $db = DB::connection($connection);
        $tables = $db->select("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");

        $db->unprepared('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $name = ((array) $table)['name'];
            if ($name !== 'migrations') {
                $db->unprepared("TRUNCATE TABLE `{$name}`");
            }
        }
        $db->unprepared('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * An institution and its user. Returns the user's and the institution's uuid, the email
     * and the password.
     *
     * @param  array{email?: string, password?: string, verified?: bool, suspended?: bool, role?: string, language?: string, removed?: bool, institution?: string|null, name?: string}  $options
     * @return array{user: string, institution: string|null, email: string, password: string}
     */
    public static function user(array $options = []): array
    {
        $db = DB::connection(useMigratorConnection());
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');
        $role = $options['role'] ?? 'owner';
        $email = strtolower($options['email'] ?? 'user'.bin2hex(random_bytes(4)).'@example.test');
        $password = $options['password'] ?? self::PASSWORD;

        $institutionId = null;
        $institutionUuid = null;
        if ($role !== 'platform_admin' && isset($options['institution'])) {
            // Another user of an institution that exists (slice 03 and after).
            $institutionUuid = $options['institution'];
            $institutionId = $db->table('institutions')->where('uuid', $institutionUuid)->value('id');
        } elseif ($role !== 'platform_admin') {
            $institutionUuid = Uuid::uuid4()->toString();
            $institutionId = $db->table('institutions')->insertGetId([
                'uuid' => $institutionUuid,
                'name' => 'Collège '.bin2hex(random_bytes(3)),
                'type' => 'other',
                'timezone' => 'America/Port-au-Prince',
                'language' => $options['language'] ?? 'fr',
                'suspended_at' => ($options['suspended'] ?? false) ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $userUuid = Uuid::uuid4()->toString();
        $db->table('users')->insert([
            'uuid' => $userUuid,
            'institution_id' => $institutionId,
            'role' => $role,
            'name' => $options['name'] ?? 'Marie Joseph',
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => ($options['verified'] ?? true) ? $now : null,
            'language' => $options['language'] ?? 'fr',
            'deleted_at' => ($options['removed'] ?? false) ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['user' => $userUuid, 'institution' => $institutionUuid, 'email' => $email, 'password' => $password];
    }

    /** One row of `users` by email, as an array, or null. */
    public static function userRow(string $email): ?array
    {
        $row = DB::connection(useMigratorConnection())->table('users')->where('email', strtolower($email))->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * Stores a token the way the API does (SHA-256 of the token, 32 bytes) for the user with
     * this email, replacing any token that user had.
     *
     * @param  'email_verification_tokens'|'password_reset_tokens'  $table
     */
    public static function plantToken(string $table, string $email, string $token, ?Carbon $expiresAt = null): void
    {
        $db = DB::connection(useMigratorConnection());
        $userId = $db->table('users')->where('email', strtolower($email))->value('id');
        $db->table($table)->where('user_id', $userId)->delete();
        $db->table($table)->insert([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token, true),
            'expires_at' => ($expiresAt ?? Carbon::now('UTC')->addHour())->format('Y-m-d H:i:s'),
            'created_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
        ]);
    }

    /** One row of `users` by uuid, as an array, or null. */
    public static function userRowByUuid(string $uuid): ?array
    {
        $row = DB::connection(useMigratorConnection())->table('users')->where('uuid', $uuid)->first();

        return $row === null ? null : (array) $row;
    }

    /** One row of `institutions` by uuid, as an array, or null. */
    public static function institutionRow(string $uuid): ?array
    {
        $row = DB::connection(useMigratorConnection())->table('institutions')->where('uuid', $uuid)->first();

        return $row === null ? null : (array) $row;
    }

    /** Sets columns of an institution row (its uuid), as an administrator would have. */
    public static function updateInstitution(string $uuid, array $values): void
    {
        DB::connection(useMigratorConnection())->table('institutions')->where('uuid', $uuid)->update($values);
    }

    /** Suspends the institution (its uuid) now. */
    public static function suspend(string $institution): void
    {
        self::updateInstitution($institution, ['suspended_at' => Carbon::now('UTC')->format('Y-m-d H:i:s')]);
    }

    /**
     * Writes an invitation the way the API does (SHA-256 of the token, 32 bytes). Returns its
     * uuid. `invited_by` is the uuid of a user of the institution.
     *
     * @param  array{institution: string, invited_by: string, email: string, role?: string, token: string, expires_at?: Carbon, accepted?: bool}  $o
     */
    public static function plantInvitation(array $o): string
    {
        $db = DB::connection(useMigratorConnection());
        $now = Carbon::now('UTC');
        $uuid = Uuid::uuid4()->toString();

        $db->table('invitations')->insert([
            'uuid' => $uuid,
            'institution_id' => $db->table('institutions')->where('uuid', $o['institution'])->value('id'),
            'invited_by_user_id' => $db->table('users')->where('uuid', $o['invited_by'])->value('id'),
            'email' => strtolower($o['email']),
            'role' => $o['role'] ?? 'manager',
            'token_hash' => hash('sha256', $o['token'], true),
            'expires_at' => ($o['expires_at'] ?? $now->copy()->addDays(7))->format('Y-m-d H:i:s'),
            'accepted_at' => ($o['accepted'] ?? false) ? $now->format('Y-m-d H:i:s') : null,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);

        return $uuid;
    }

    /**
     * The rows of `invitations`, optionally of one institution (its uuid).
     *
     * @return list<array<string, mixed>>
     */
    public static function invitationRows(?string $institution = null): array
    {
        $db = DB::connection(useMigratorConnection());
        $query = $db->table('invitations');
        if ($institution !== null) {
            $query->where('institution_id', $db->table('institutions')->where('uuid', $institution)->value('id'));
        }

        return $query->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    /**
     * Writes an election directly, in any status and with any dates (slice 05 and after). Returns its
     * uuid. `institution` is the uuid of its institution; dates are UTC strings or Carbon values.
     *
     * @param  array{institution: string, title?: string, description?: string|null, status?: string, starts_at?: Carbon|string, ends_at?: Carbon|string, timezone?: string, language?: string, candidate_order?: string, results_display?: string, cover_file?: string|null, created_at?: Carbon|string, parent?: string|null, opened_at?: Carbon|string|null, closed_at?: Carbon|string|null, published_at?: Carbon|string|null, archived_at?: Carbon|string|null}  $o
     */
    public static function plantElection(array $o): string
    {
        $db = DB::connection(useMigratorConnection());
        $fmt = fn ($value) => $value === null ? null : Carbon::parse($value, 'UTC')->format('Y-m-d H:i:s');
        $uuid = Uuid::uuid4()->toString();
        $created = $fmt($o['created_at'] ?? Carbon::now('UTC'));

        $db->table('elections')->insert([
            'uuid' => $uuid,
            'institution_id' => $db->table('institutions')->where('uuid', $o['institution'])->value('id'),
            'parent_election_id' => isset($o['parent']) ? $db->table('elections')->where('uuid', $o['parent'])->value('id') : null,
            'title' => $o['title'] ?? 'Élection '.bin2hex(random_bytes(3)),
            'description' => $o['description'] ?? null,
            'starts_at' => $fmt($o['starts_at'] ?? '2026-10-12 12:00:00'),
            'ends_at' => $fmt($o['ends_at'] ?? '2026-10-16 19:00:00'),
            'timezone' => $o['timezone'] ?? 'America/Port-au-Prince',
            'language' => $o['language'] ?? 'fr',
            'status' => $o['status'] ?? 'draft',
            'cover_file' => $o['cover_file'] ?? null,
            'candidate_order' => $o['candidate_order'] ?? 'manual',
            'results_display' => $o['results_display'] ?? 'full',
            'opened_at' => $fmt($o['opened_at'] ?? null),
            'closed_at' => $fmt($o['closed_at'] ?? null),
            'published_at' => $fmt($o['published_at'] ?? null),
            'archived_at' => $fmt($o['archived_at'] ?? null),
            'created_at' => $created,
            'updated_at' => $created,
        ]);

        return $uuid;
    }

    /** One row of `elections` by uuid, as an array, or null. */
    public static function electionRow(string $uuid): ?array
    {
        $row = DB::connection(useMigratorConnection())->table('elections')->where('uuid', $uuid)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * The rows of `elections`, optionally of one institution (its uuid), oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function electionRows(?string $institution = null): array
    {
        $db = DB::connection(useMigratorConnection());
        $query = $db->table('elections');
        if ($institution !== null) {
            $query->where('institution_id', $db->table('institutions')->where('uuid', $institution)->value('id'));
        }

        return $query->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    /**
     * Inserts a ballot straight into the database (slice 06). Options: `election` (uuid, required), `title`,
     * `description`, `position`, `seats`, `allow_blank`. The institution is the election's. Returns the uuid.
     */
    public static function plantBallot(array $o): string
    {
        $db = DB::connection(useMigratorConnection());
        $election = $db->table('elections')->where('uuid', $o['election'])->first();
        $uuid = Uuid::uuid4()->toString();
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');

        $db->table('ballots')->insert([
            'uuid' => $uuid,
            'institution_id' => $election->institution_id,
            'election_id' => $election->id,
            'title' => $o['title'] ?? 'Poste '.bin2hex(random_bytes(3)),
            'description' => $o['description'] ?? null,
            'position' => $o['position'] ?? ((int) $db->table('ballots')->where('election_id', $election->id)->max('position') + 1),
            'seats' => $o['seats'] ?? 1,
            'allow_blank' => $o['allow_blank'] ?? true,
            'scope' => 'general',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    /** One row of `ballots` by uuid, as an array, or null. */
    public static function ballotRow(string $uuid): ?array
    {
        $row = DB::connection(useMigratorConnection())->table('ballots')->where('uuid', $uuid)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * The rows of `ballots` of one election (its uuid), in display order.
     *
     * @return list<array<string, mixed>>
     */
    public static function ballotRows(string $election): array
    {
        $db = DB::connection(useMigratorConnection());
        $id = $db->table('elections')->where('uuid', $election)->value('id');

        return $db->table('ballots')->where('election_id', $id)->orderBy('position')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    /**
     * Inserts a party straight into the database (slice 06b). Options: `election` (uuid, required), `name`,
     * `acronym`, `colour`. The institution is the election's. Returns the uuid.
     */
    public static function plantParty(array $o): string
    {
        $db = DB::connection(useMigratorConnection());
        $election = $db->table('elections')->where('uuid', $o['election'])->first();
        $uuid = Uuid::uuid4()->toString();
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');
        $name = $o['name'] ?? 'Parti '.bin2hex(random_bytes(3));

        $db->table('parties')->insert([
            'uuid' => $uuid,
            'institution_id' => $election->institution_id,
            'election_id' => $election->id,
            'name' => $name,
            'name_key' => mb_strtolower(trim($name)),
            'acronym' => $o['acronym'] ?? null,
            'colour' => $o['colour'] ?? '#5468D4',
            'logo_file' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    /** One row of `parties` by uuid, as an array, or null. */
    public static function partyRow(string $uuid): ?array
    {
        $row = DB::connection(useMigratorConnection())->table('parties')->where('uuid', $uuid)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * The rows of `parties` of one election (its uuid), by name then creation.
     *
     * @return list<array<string, mixed>>
     */
    public static function partyRows(string $election): array
    {
        $db = DB::connection(useMigratorConnection());
        $id = $db->table('elections')->where('uuid', $election)->value('id');

        return $db->table('parties')->where('election_id', $id)->orderBy('name_key')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    /** A new random token of the right shape: 64 hexadecimal characters. */
    public static function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Makes the user's token in this table expire a minute ago. */
    public static function expireToken(string $table, string $email): void
    {
        $db = DB::connection(useMigratorConnection());
        $userId = $db->table('users')->where('email', strtolower($email))->value('id');
        $db->table($table)->where('user_id', $userId)->update(['expires_at' => Carbon::now('UTC')->subMinute()->format('Y-m-d H:i:s')]);
    }

    /** The rows of a token table for this user's email. */
    public static function tokenRows(string $table, string $email): array
    {
        $db = DB::connection(useMigratorConnection());
        $userId = $db->table('users')->where('email', strtolower($email))->value('id');

        return $db->table($table)->where('user_id', $userId)->get()->map(fn ($row) => (array) $row)->all();
    }

    /**
     * The emails sent so far in this test (the `array` mailer).
     *
     * @return list<array{to: list<string>, subject: string, text: string, html: string}>
     */
    public static function mail(): array
    {
        $sent = [];

        foreach (app('mail.manager')->mailer('array')->getSymfonyTransport()->messages() as $message) {
            $email = $message->getOriginalMessage();
            $sent[] = [
                'to' => array_map(fn ($a) => strtolower($a->getAddress()), $email->getTo()),
                'subject' => (string) $email->getSubject(),
                'text' => (string) $email->getTextBody(),
                'html' => (string) $email->getHtmlBody(),
            ];
        }

        return $sent;
    }

    /** The emails sent to this address. */
    public static function mailTo(string $email): array
    {
        return array_values(array_filter(self::mail(), fn ($m) => in_array(strtolower($email), $m['to'], true)));
    }

    /**
     * The token in the link of an email: `{APP_URL}/{path}?token=<64 hex>`. Null when there
     * is no such link.
     */
    public static function tokenIn(array $mail, string $path): ?string
    {
        $app = preg_quote(rtrim((string) config('app.url'), '/'), '#');
        $path = preg_quote($path, '#');

        foreach ([$mail['text'], $mail['html']] as $body) {
            if (preg_match('#'.$app.$path.'\?token=([0-9a-f]{64})(?![0-9a-f])#', $body, $found)) {
                return $found[1];
            }
        }

        return null;
    }
}
