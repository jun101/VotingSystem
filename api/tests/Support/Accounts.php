<?php

namespace Tests\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
     * `description`, `position`, `seats`, `allow_blank`, `groups` (group uuids: the ballot is then a group ballot,
     * slice 09). The institution is the election's. Returns the uuid.
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
            'scope' => empty($o['groups']) ? 'general' : 'group',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($o['groups'] ?? [] as $group) {
            $db->table('ballot_voter_group')->insert([
                'ballot_id' => $db->table('ballots')->where('uuid', $uuid)->value('id'),
                'voter_group_id' => $db->table('voter_groups')->where('uuid', $group)->value('id'),
            ]);
        }

        return $uuid;
    }

    /**
     * The uuids of the groups a ballot covers (slice 09), sorted.
     *
     * @return list<string>
     */
    public static function ballotGroups(string $ballot): array
    {
        $db = DB::connection(useMigratorConnection());
        $id = $db->table('ballots')->where('uuid', $ballot)->value('id');

        return $db->table('ballot_voter_group')
            ->join('voter_groups', 'voter_groups.id', '=', 'ballot_voter_group.voter_group_id')
            ->where('ballot_voter_group.ballot_id', $id)
            ->orderBy('voter_groups.uuid')
            ->pluck('voter_groups.uuid')
            ->all();
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
            'logo_file' => $o['logo_file'] ?? null,
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

    /**
     * Inserts a candidate straight into the database (slice 06c). Options: `ballot` (uuid, required), `party`
     * (uuid or null), `first_name`, `last_name`, `sex`, `slogan`, `biography`, `position`. The institution and the
     * election are the ballot's. Returns the uuid.
     */
    public static function plantCandidate(array $o): string
    {
        $db = DB::connection(useMigratorConnection());
        $ballot = $db->table('ballots')->where('uuid', $o['ballot'])->first();
        $uuid = Uuid::uuid4()->toString();
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');

        $db->table('candidates')->insert([
            'uuid' => $uuid,
            'institution_id' => $ballot->institution_id,
            'election_id' => $ballot->election_id,
            'ballot_id' => $ballot->id,
            'party_id' => isset($o['party']) ? $db->table('parties')->where('uuid', $o['party'])->value('id') : null,
            'first_name' => $o['first_name'] ?? 'Prénom',
            'last_name' => $o['last_name'] ?? 'Nom '.bin2hex(random_bytes(2)),
            'sex' => $o['sex'] ?? 'female',
            'slogan' => $o['slogan'] ?? null,
            'biography' => $o['biography'] ?? null,
            'photo_file' => $o['photo_file'] ?? null,
            'position' => $o['position'] ?? ((int) $db->table('candidates')->where('ballot_id', $ballot->id)->max('position') + 1),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    /** One row of `candidates` by uuid, as an array, or null. */
    public static function candidateRow(string $uuid): ?array
    {
        $row = DB::connection(useMigratorConnection())->table('candidates')->where('uuid', $uuid)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * The rows of `candidates` of one ballot (its uuid), in display order.
     *
     * @return list<array<string, mixed>>
     */
    public static function candidateRows(string $ballot): array
    {
        $db = DB::connection(useMigratorConnection());
        $id = $db->table('ballots')->where('uuid', $ballot)->value('id');

        return $db->table('candidates')->where('ballot_id', $id)->orderBy('position')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
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

    /**
     * Inserts a voter group straight into the database (slice 07). Options: `election` (uuid, required), `name`.
     * The institution is the election's. Returns the uuid.
     */
    public static function plantGroup(array $o): string
    {
        $db = DB::connection(useMigratorConnection());
        $election = $db->table('elections')->where('uuid', $o['election'])->first();
        $uuid = Uuid::uuid4()->toString();
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');
        $name = $o['name'] ?? 'Groupe '.bin2hex(random_bytes(3));

        $db->table('voter_groups')->insert([
            'uuid' => $uuid,
            'institution_id' => $election->institution_id,
            'election_id' => $election->id,
            'name' => $name,
            'name_key' => mb_strtolower(trim($name)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    /** One row of `voter_groups` by uuid, as an array, or null. */
    public static function groupRow(string $uuid): ?array
    {
        $row = DB::connection(useMigratorConnection())->table('voter_groups')->where('uuid', $uuid)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * The rows of `voter_groups` of one election (its uuid), by name then creation.
     *
     * @return list<array<string, mixed>>
     */
    public static function groupRows(string $election): array
    {
        $db = DB::connection(useMigratorConnection());
        $id = $db->table('elections')->where('uuid', $election)->value('id');

        return $db->table('voter_groups')->where('election_id', $id)->orderBy('name_key')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    /**
     * Inserts a voter straight into the database (slice 07). Options: `election` (uuid, required), `group` (a group
     * uuid or null), `full_name`, `identifier`, `email`, `phone`. The institution is the election's. Returns the uuid.
     */
    public static function plantVoter(array $o): string
    {
        $db = DB::connection(useMigratorConnection());
        $election = $db->table('elections')->where('uuid', $o['election'])->first();
        $group = ($o['group'] ?? null) === null ? null : $db->table('voter_groups')->where('uuid', $o['group'])->value('id');
        $uuid = Uuid::uuid4()->toString();
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');

        $db->table('voters')->insert([
            'uuid' => $uuid,
            'institution_id' => $election->institution_id,
            'election_id' => $election->id,
            'voter_group_id' => $group,
            'full_name' => $o['full_name'] ?? 'Électeur '.bin2hex(random_bytes(3)),
            'identifier' => $o['identifier'] ?? null,
            'email' => $o['email'] ?? null,
            'phone' => $o['phone'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    /** One row of `voters` by uuid, as an array, or null. */
    public static function voterRow(string $uuid): ?array
    {
        $row = DB::connection(useMigratorConnection())->table('voters')->where('uuid', $uuid)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * The rows of `voters` of one election (its uuid), by full name then creation.
     *
     * @return list<array<string, mixed>>
     */
    public static function voterRows(string $election): array
    {
        $db = DB::connection(useMigratorConnection());
        $id = $db->table('elections')->where('uuid', $election)->value('id');

        return $db->table('voters')->where('election_id', $id)->orderBy('full_name')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    /**
     * A voter import with its checked rows (slice 08), written straight into the tables of docs/design/database.md.
     * The counts are worked out from the rows.
     *
     * @param  array{election: string, status?: string, name?: string, columns?: list<string>|null, created_at?: string, updated_at?: string, mapping?: array<string, int|null>, failure?: string|null, processed?: int, imported?: int, new_groups?: list<string>, rows?: list<array{status?: string, reasons?: list<string>, full_name?: string|null, group?: string|null, identifier?: string|null, email?: string|null, phone?: string|null, line?: int}>}  $o
     */
    public static function plantImport(array $o): string
    {
        $db = DB::connection(useMigratorConnection());
        $election = $db->table('elections')->where('uuid', $o['election'])->first();
        $uuid = Uuid::uuid4()->toString();
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');
        $rows = $o['rows'] ?? [];
        $valid = count(array_filter($rows, fn ($r) => ($r['status'] ?? 'ok') === 'ok'));
        $duplicates = count(array_filter($rows, fn ($r) => ($r['status'] ?? 'ok') === 'duplicate'));
        $groups = [];
        foreach ($rows as $r) {
            if (($r['status'] ?? 'ok') === 'ok' && ($r['group'] ?? null) !== null) {
                $groups[mb_strtolower(trim($r['group']))] ??= trim($r['group']);
            }
        }

        $id = $db->table('voter_imports')->insertGetId([
            'uuid' => $uuid,
            'institution_id' => $election->institution_id,
            'election_id' => $election->id,
            'user_id' => null,
            'original_name' => $o['name'] ?? 'eleves.xlsx',
            'stored_file' => Uuid::uuid4()->toString(),
            'columns' => array_key_exists('columns', $o) && $o['columns'] === null ? null : json_encode($o['columns'] ?? Spreadsheets::HEADER),
            'mapping' => json_encode($o['mapping'] ?? ['full_name' => 0, 'group' => 1, 'identifier' => 2, 'email' => 3, 'phone' => 4]),
            'status' => $o['status'] ?? 'checked',
            'failure' => $o['failure'] ?? null,
            'total_rows' => count($rows),
            'valid_rows' => $valid,
            'invalid_rows' => count($rows) - $valid,
            'duplicate_rows' => $duplicates,
            'new_groups' => json_encode($o['new_groups'] ?? array_values($groups)),
            'processed_rows' => $o['processed'] ?? 0,
            'imported_rows' => $o['imported'] ?? 0,
            'finished_at' => ($o['status'] ?? 'checked') === 'done' ? $now : null,
            'created_at' => $o['created_at'] ?? $now,
            'updated_at' => $o['updated_at'] ?? $now,
        ]);

        foreach (array_values($rows) as $i => $r) {
            $values = [
                'full_name' => $r['full_name'] ?? null,
                'group' => $r['group'] ?? null,
                'identifier' => $r['identifier'] ?? null,
                'email' => $r['email'] ?? null,
                'phone' => $r['phone'] ?? null,
            ];
            $db->table('voter_import_rows')->insert([
                'institution_id' => $election->institution_id,
                'voter_import_id' => $id,
                'line' => $r['line'] ?? $i + 2,
                'status' => $r['status'] ?? 'ok',
                'reasons' => json_encode($r['reasons'] ?? []),
                'full_name' => $values['full_name'],
                'group_name' => $values['group'],
                'identifier' => $values['identifier'],
                'email' => $values['email'],
                'phone' => $values['phone'],
                'raw' => json_encode($values),
            ]);
        }

        return $uuid;
    }

    /** Sets the status of an election straight in the table (the rows already planted stay as they are). */
    public static function setElectionStatus(string $uuid, string $status): void
    {
        DB::connection(useMigratorConnection())->table('elections')->where('uuid', $uuid)->update(['status' => $status]);
    }

    /** One row of `voter_imports` by uuid, as an array, or null. */
    public static function importRow(string $uuid): ?array
    {
        $row = DB::connection(useMigratorConnection())->table('voter_imports')->where('uuid', $uuid)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * The rows of `voter_import_rows` of one import (its uuid), by line.
     *
     * @return list<array<string, mixed>>
     */
    public static function importRows(string $uuid): array
    {
        $db = DB::connection(useMigratorConnection());
        $id = $db->table('voter_imports')->where('uuid', $uuid)->value('id');

        return $db->table('voter_import_rows')->where('voter_import_id', $id)->orderBy('line')->get()->map(fn ($row) => (array) $row)->all();
    }

    /** The uuids of the imports of one election, oldest first. */
    public static function importsOf(string $election): array
    {
        $db = DB::connection(useMigratorConnection());
        $id = $db->table('elections')->where('uuid', $election)->value('id');

        return $db->table('voter_imports')->where('election_id', $id)->orderBy('id')->pluck('uuid')->all();
    }

    /**
     * The current code of a voter (slice 10), written straight into `credentials` as the application stores it: only the
     * HMAC. `voter` is the voter's uuid; `code` the code in clear (`XXXXX-XXXXX`, generated when absent). Returns the code.
     *
     * @param  array{voter: string, code?: string, issued_at?: string, reissue_count?: int, email_status?: string|null}  $o
     */
    public static function plantCredential(array $o): string
    {
        $db = DB::connection(useMigratorConnection());
        $voter = $db->table('voters')->where('uuid', $o['voter'])->first();
        $code = $o['code'] ?? Codes::fresh();
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');

        $db->table('credentials')->insert([
            'institution_id' => $voter->institution_id,
            'election_id' => $voter->election_id,
            'voter_id' => $voter->id,
            'code_hash' => Codes::hash($code),
            'issued_at' => $o['issued_at'] ?? $now,
            'issued_by_user_id' => null,
            'reissue_count' => $o['reissue_count'] ?? 0,
            'email_status' => $o['email_status'] ?? null,
        ]);

        return $code;
    }

    /** The row of `credentials` of one voter (its uuid), as an array, or null. */
    public static function credentialRow(string $voter): ?array
    {
        $db = DB::connection(useMigratorConnection());
        $row = $db->table('credentials')->where('voter_id', $db->table('voters')->where('uuid', $voter)->value('id'))->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * The rows of `credentials` of one election (its uuid), by voter name.
     *
     * @return list<array<string, mixed>>
     */
    public static function credentialRows(string $election): array
    {
        $db = DB::connection(useMigratorConnection());
        $id = $db->table('elections')->where('uuid', $election)->value('id');

        return $db->table('credentials')
            ->join('voters', 'voters.id', '=', 'credentials.voter_id')
            ->where('credentials.election_id', $id)
            ->orderBy('voters.full_name')
            ->get(['credentials.*', 'voters.uuid as voter_uuid', 'voters.full_name as full_name'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * A file of slips (slice 10), written into `generated_files` and, for a ready file, onto the `credentials` disk as
     * the application stores it (`Crypt::encryptString` of the PDF bytes under a random name). Returns its uuid.
     *
     * @param  array{election: string, user?: string, group?: string|null, status?: string, part?: int, parts?: int, voter_count?: int, pdf?: string, failure?: string|null, expires_at?: string, created_at?: string}  $o
     */
    public static function plantGeneratedFile(array $o): string
    {
        $db = DB::connection(useMigratorConnection());
        $election = $db->table('elections')->where('uuid', $o['election'])->first();
        $uuid = Uuid::uuid4()->toString();
        $status = $o['status'] ?? 'ready';
        $stored = null;
        $size = null;
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');

        if ($status === 'ready') {
            $pdf = $o['pdf'] ?? Pdfs::sample();
            $stored = Uuid::uuid4()->toString();
            Storage::disk('credentials')->put($stored, Crypt::encryptString($pdf));
            $size = strlen($pdf);
        }

        $user = isset($o['user'])
            ? $db->table('users')->where('uuid', $o['user'])->value('id')
            : $db->table('users')->where('institution_id', $election->institution_id)->orderBy('id')->value('id');

        $db->table('generated_files')->insert([
            'uuid' => $uuid,
            'institution_id' => $election->institution_id,
            'election_id' => $election->id,
            'user_id' => $user,
            'kind' => 'slips_pdf',
            'voter_group_id' => ($o['group'] ?? null) === null ? null : $db->table('voter_groups')->where('uuid', $o['group'])->value('id'),
            'part' => $o['part'] ?? 1,
            'parts' => $o['parts'] ?? 1,
            'voter_count' => $o['voter_count'] ?? 1,
            'status' => $status,
            'failure' => $o['failure'] ?? ($status === 'failed' ? 'generation_failed' : null),
            'stored_file' => $stored,
            'size_bytes' => $size,
            'expires_at' => Carbon::parse($o['expires_at'] ?? '2030-03-05 19:00:00', 'UTC')->format('Y-m-d H:i:s'),
            'created_at' => Carbon::parse($o['created_at'] ?? $now, 'UTC')->format('Y-m-d H:i:s'),
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    /**
     * The rows of `generated_files` of one election (its uuid), oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function generatedFileRows(string $election): array
    {
        $db = DB::connection(useMigratorConnection());
        $id = $db->table('elections')->where('uuid', $election)->value('id');

        return $db->table('generated_files')->where('election_id', $id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }
}
