<?php

/*
 * A new route, model or table without a tenant test fails the suite —
 * docs/slices/03-admin-shell-and-tenant-isolation.md, part 3 and rule 5.
 * The lists are in Tests\Support\Tenancy.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Tenancy;

it('has every route of the API in one of the lists of the tenant suite [NFR-SEC-03] (rule 5)', function () {
    $missing = Tenancy::uncoveredRoutes(Route::getRoutes());

    expect($missing)->toBe([], "Routes with no tenant test and in no list of tests/Support/Tenancy.php:\n".implode("\n", $missing));
});

it('notices a route that is in no list [NFR-SEC-03] (rule 5)', function () {
    Route::get('/api/v1/not-a-real-thing', fn () => response()->json([]));
    Route::delete('/api/v1/not-a-real-thing/{thing}', fn () => response()->noContent());

    expect(Tenancy::uncoveredRoutes(Route::getRoutes()))
        ->toBe(['DELETE api/v1/not-a-real-thing/{thing}', 'GET api/v1/not-a-real-thing']);
});

it('names an existing test file, mentioning the route, for every tenant route [NFR-SEC-03] (rule 5)', function () {
    // Empty until slice 05 adds the first tenant routes.
    expect(Tenancy::TENANT_ROUTES)->toBeArray();

    foreach (Tenancy::TENANT_ROUTES as $route => $file) {
        $path = base_path('tests/Acceptance/'.$file);

        expect(is_file($path))->toBeTrue("{$route}: {$file} does not exist");

        $uri = explode(' ', $route, 2)[1];
        expect(str_contains(file_get_contents($path), $uri))->toBeTrue("{$route}: {$file} never mentions the route");
    }
});

it('has a route behind every entry of the lists [NFR-SEC-03] (rule 5)', function () {
    $registered = [];
    foreach (Route::getRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            $registered[] = $method.' '.$route->uri();
        }
    }

    $listed = array_merge(Tenancy::PUBLIC_ROUTES, Tenancy::OWN_USER_ROUTES, array_keys(Tenancy::TENANT_ROUTES));

    expect(array_values(array_diff($listed, $registered)))->toBe([], 'Listed routes that no longer exist: remove them from Tenancy.php');
});

it('has the trait on every model of app/Models that has an institution_id column [NFR-SEC-03] (rule 5)', function () {
    $problems = [];

    foreach (glob(base_path('app/Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        $model = new $class;
        $columns = Illuminate\Support\Facades\Schema::connection(useMigratorConnection())->getColumnListing($model->getTable());
        $hasColumn = in_array('institution_id', $columns, true);

        if ($hasColumn && ! Tenancy::usesTrait($class)) {
            $problems[] = "{$class} has institution_id and does not use BelongsToInstitution";
        }
        if (! $hasColumn && ! array_key_exists($class, Tenancy::MODELS_WITHOUT_TENANT_COLUMN)) {
            $problems[] = "{$class} has no institution_id column and is not in MODELS_WITHOUT_TENANT_COLUMN with a reason";
        }
        if (! $hasColumn && Tenancy::usesTrait($class)) {
            $problems[] = "{$class} uses BelongsToInstitution but its table has no institution_id";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('gives a reason for every entry of the lists of models and tables [NFR-SEC-03] (rule 5)', function () {
    foreach ([Tenancy::MODELS_WITHOUT_TENANT_COLUMN, Tenancy::TABLES_WITHOUT_TENANT_MODEL] as $list) {
        foreach ($list as $name => $reason) {
            expect(mb_strlen(trim($reason)))->toBeGreaterThan(5, "{$name} has no reason");
        }
    }
});

it('has a model with the trait for every table that has an institution_id column [NFR-SEC-03] (rule 5)', function () {
    $db = DB::connection(useMigratorConnection());
    $tables = collect($db->select("SELECT DISTINCT TABLE_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'institution_id'"))
        ->map(fn ($row) => ((array) $row)['name'])->all();

    $covered = [];
    foreach (glob(base_path('app/Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        if (Tenancy::usesTrait($class)) {
            $covered[] = (new $class)->getTable();
        }
    }

    $missing = array_values(array_diff($tables, $covered, array_keys(Tenancy::TABLES_WITHOUT_TENANT_MODEL)));

    expect($tables)->toContain('users')
        ->and($missing)->toBe([], 'Tables with institution_id and no tenant model nor reason: '.implode(', ', $missing));
});

it('has no entry of TABLES_WITHOUT_TENANT_MODEL that is a table without the column [NFR-SEC-03] (rule 5)', function () {
    $db = DB::connection(useMigratorConnection());

    foreach (array_keys(Tenancy::TABLES_WITHOUT_TENANT_MODEL) as $table) {
        expect(Illuminate\Support\Facades\Schema::connection(useMigratorConnection())->hasColumn($table, 'institution_id'))
            ->toBeTrue("{$table} is listed but has no institution_id column");
    }
});
