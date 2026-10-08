<?php

namespace Tests\Support;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\Support\Tenancy\TenantProbe;
use Tests\Support\Tenancy\TenantProbeNote;

/**
 * Everything the tenant-isolation suite (slice 03) shares, and the lists that every later
 * slice extends (docs/slices/03-admin-shell-and-tenant-isolation.md, part 3).
 *
 * ADDING A ROUTE, A TENANT MODEL OR A TENANT TABLE WITHOUT TOUCHING THIS FILE MAKES
 * `make check` FAIL ON PURPOSE. The coverage tests read the three lists below.
 *
 * Part of the acceptance harness: a slice adds its own entries; it never removes one.
 */
final class Tenancy
{
    /**
     * Routes that need no institution user and bind no record: the probe is the answer.
     * Written `METHOD uri` as the router knows them.
     */
    public const PUBLIC_ROUTES = [
        'GET api/v1/health',
        'GET api/v1/auth/csrf',
        'POST api/v1/auth/register',
        'POST api/v1/auth/login',
        'POST api/v1/auth/verify-email',
        'POST api/v1/auth/forgot-password',
        'POST api/v1/auth/reset-password',
        'POST api/v1/auth/accept-invitation',
        // Slice 04b: finishes a sign-in started with a password; a pending sign-in in the session is its only input.
        'POST api/v1/auth/two-factor-challenge',
    ];

    /**
     * Routes of the signed-in user about themselves only: they name no record, so there is
     * nothing of another institution to reach; the endpoint's own tests check that no
     * request can name another user.
     */
    public const OWN_USER_ROUTES = [
        'POST api/v1/auth/logout',
        'GET api/v1/auth/me',
        'PATCH api/v1/auth/me',
        'POST api/v1/auth/verify-email/resend',
        // Slice 04: the institution is the tenant itself; these routes name no record of it.
        'GET api/v1/institution',
        'PATCH api/v1/institution',
        'PUT api/v1/institution/logo',
        'DELETE api/v1/institution/logo',
        // Slice 04b: the user's own second factor; no record is named.
        'GET api/v1/auth/two-factor',
        'POST api/v1/auth/two-factor/setup',
        'POST api/v1/auth/two-factor/confirm',
        'POST api/v1/auth/two-factor/disable',
        'POST api/v1/auth/two-factor/recovery-codes',
    ];

    /**
     * Routes that bind or list tenant records: `METHOD uri` => the test file (under
     * `api/tests/Acceptance/`) that checks them against a user of another institution.
     * Slice 05 and after add their routes here. The file must exist and must name the uri.
     *
     * @var array<string, string>
     */
    public const TENANT_ROUTES = [
        'GET api/v1/users' => 'Slice04/ListUsersTest.php',
        'DELETE api/v1/users/{user}' => 'Slice04/RemoveUserTest.php',
        'GET api/v1/invitations' => 'Slice04/ListInvitationsTest.php',
        'POST api/v1/invitations' => 'Slice04/CreateInvitationTest.php',
        'DELETE api/v1/invitations/{invitation}' => 'Slice04/CancelInvitationTest.php',
        'POST api/v1/users/{user}/two-factor/reset' => 'Slice04b/ResetUserTwoFactorTest.php',
    ];

    /**
     * Tables with an `institution_id` column that have no model with the trait, with the
     * reason. A table that is not here must be the table of a model using
     * BelongsToInstitution.
     *
     * @var array<string, string>
     */
    public const TABLES_WITHOUT_TENANT_MODEL = [
        'tenant_probes' => 'test-only table, model in tests/Support/Tenancy',
        'tenant_probe_notes' => 'test-only table, model in tests/Support/Tenancy',
    ];

    /**
     * Models of `app/Models` that have no `institution_id` column: class => reason.
     *
     * @var array<string, string>
     */
    public const MODELS_WITHOUT_TENANT_COLUMN = [
        'App\Models\Institution' => 'it is the tenant',
    ];

    /** Creates the two test-only tables (once per run) and gives the `app` account its rights. */
    public static function installProbeTables(): void
    {
        $connection = useMigratorConnection();
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('tenant_probes')) {
            $schema->create('tenant_probes', function ($table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('institution_id')->constrained('institutions');
                $table->string('title', 150);
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
            $schema->create('tenant_probe_notes', function ($table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('institution_id')->constrained('institutions');
                $table->foreignId('tenant_probe_id')->constrained('tenant_probes');
                $table->string('body', 255);
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
            expect(Artisan::call('db:grant-app', ['--database' => $connection]))->toBe(0);
        }
    }

    /**
     * Two institutions, each with an owner, a manager, a probe and a note on that probe.
     *
     * @return array{a: array<string, mixed>, b: array<string, mixed>}
     */
    public static function twoInstitutions(): array
    {
        $out = [];

        foreach (['a', 'b'] as $key) {
            $owner = Accounts::user(['role' => 'owner']);
            $manager = Accounts::user(['role' => 'manager', 'institution' => $owner['institution']]);
            $probe = self::probe($owner['institution'], "Probe {$key}1");
            $second = self::probe($owner['institution'], "Probe {$key}2");
            $note = self::note($probe['uuid'], "Note {$key}1");

            $out[$key] = ['owner' => $owner, 'manager' => $manager, 'institution' => $owner['institution'], 'probe' => $probe, 'second' => $second, 'note' => $note];
        }

        return $out;
    }

    /** @return array{uuid: string, title: string} */
    public static function probe(string $institutionUuid, string $title): array
    {
        $db = DB::connection(useMigratorConnection());
        $uuid = Uuid::uuid4()->toString();
        $db->table('tenant_probes')->insert([
            'uuid' => $uuid,
            'institution_id' => $db->table('institutions')->where('uuid', $institutionUuid)->value('id'),
            'title' => $title,
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);

        return ['uuid' => $uuid, 'title' => $title];
    }

    /** @return array{uuid: string, body: string} */
    public static function note(string $probeUuid, string $body): array
    {
        $db = DB::connection(useMigratorConnection());
        $uuid = Uuid::uuid4()->toString();
        $probe = $db->table('tenant_probes')->where('uuid', $probeUuid)->first();
        $db->table('tenant_probe_notes')->insert([
            'uuid' => $uuid,
            'institution_id' => $probe->institution_id,
            'tenant_probe_id' => $probe->id,
            'body' => $body,
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);

        return ['uuid' => $uuid, 'body' => $body];
    }

    /** The institution (numeric key) of a probe row, read with the migrator. */
    public static function probeInstitutionKey(string $probeUuid): ?int
    {
        $value = DB::connection(useMigratorConnection())->table('tenant_probes')->where('uuid', $probeUuid)->value('institution_id');

        return $value === null ? null : (int) $value;
    }

    public static function institutionKey(string $institutionUuid): int
    {
        return (int) DB::connection(useMigratorConnection())->table('institutions')->where('uuid', $institutionUuid)->value('id');
    }

    public static function probeCount(): int
    {
        return (int) DB::connection(useMigratorConnection())->table('tenant_probes')->count();
    }

    /**
     * Registers the test-only routes, bound through the application's own mechanism (implicit
     * binding on a model that uses the trait). The application registers none of these.
     *
     * - GET    /api/v1/probes                       the caller's probes, paginated shape
     * - POST   /api/v1/probes                       create
     * - GET    /api/v1/probes/{probe}               show
     * - PATCH  /api/v1/probes/{probe}               update the title
     * - DELETE /api/v1/probes/{probe}               delete
     * - GET    /api/v1/probes/{probe}/notes/{note}  a child, bound through its parent
     * - GET    /api/v1/probes-open                  the probes, on a route WITHOUT sign-in
     */
    public static function registerProbeRoutes(): void
    {
        $shape = fn (TenantProbe $p) => ['id' => $p->uuid, 'title' => $p->title];

        Route::prefix('api/v1')->middleware(['api', 'cookie-session', 'auth', 'institution.active'])->group(function () use ($shape): void {
            Route::get('/probes', fn () => response()->json([
                'data' => TenantProbe::query()->orderBy('title')->get()->map($shape)->all(),
                'meta' => ['page' => 1, 'per_page' => 25, 'total' => TenantProbe::query()->count()],
            ]));
            Route::post('/probes', function (Request $request) use ($shape) {
                $probe = TenantProbe::create(['title' => (string) $request->input('title', 'Untitled')]);

                return response()->json(['data' => $shape($probe)], 201);
            });
            Route::get('/probes/{probe}', fn (TenantProbe $probe) => response()->json(['data' => $shape($probe)]));
            Route::patch('/probes/{probe}', function (Request $request, TenantProbe $probe) use ($shape) {
                $probe->update(['title' => (string) $request->input('title', $probe->title)]);

                return response()->json(['data' => $shape($probe)]);
            });
            Route::delete('/probes/{probe}', function (TenantProbe $probe) {
                $probe->delete();

                return response()->noContent();
            });
            Route::get('/probes/{probe}/notes/{note}', fn (TenantProbe $probe, TenantProbeNote $note) => response()->json(
                ['data' => ['id' => $note->uuid, 'body' => $note->body, 'probe' => $probe->uuid]],
            ))->scopeBindings();
        });

        // No `auth`: whoever is (or was, on this worker) signed in must not decide what it shows.
        Route::prefix('api/v1')->middleware(['api', 'cookie-session'])->group(function (): void {
            Route::get('/probes-open', fn () => response()->json(['data' => TenantProbe::query()->get()->map(fn (TenantProbe $p) => $p->uuid)->all()]));
        });
    }

    /**
     * The routes under `api/v1` that are in no list: the coverage test of the suite. A route
     * under `api/v1/probes` is the tests' own and is ignored.
     *
     * @param  iterable<LaravelRoute>  $routes
     * @return list<string>
     */
    public static function uncoveredRoutes(iterable $routes): array
    {
        $known = array_merge(self::PUBLIC_ROUTES, self::OWN_USER_ROUTES, array_keys(self::TENANT_ROUTES));
        $missing = [];

        foreach ($routes as $route) {
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }
            // The test-only routes of registerProbeRoutes().
            if (str_starts_with($route->uri(), 'api/v1/probes')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $label = $method.' '.$route->uri();
                if (! in_array($label, $known, true)) {
                    $missing[] = $label;
                }
            }
        }

        sort($missing);

        return $missing;
    }

    /** True when the class uses the trait, directly or through a parent. */
    public static function usesTrait(string $class): bool
    {
        return in_array(BelongsToInstitution::class, class_uses_recursive($class), true);
    }
}
