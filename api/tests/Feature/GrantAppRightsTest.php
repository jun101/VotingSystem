<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * docs/design/database.md section 6: `app` cannot UPDATE or DELETE the rows of the
 * append-only tables. The rights are given table by table by `php artisan db:grant-app`,
 * run with the `migrator` account. The test creates its own tables (it never touches a
 * real one) in the test database and cleans up after itself.
 *
 * The `migrator` account is given to the test container only (tests/migrator.php; the
 * `test` service of compose.yaml: `make test-api`), never to the api, queue or scheduler
 * containers.
 */

const APPEND_ONLY_PROBE = 'zz_probe_append_only';
const PLAIN_PROBE = 'zz_probe_plain';

function grantRights(string $connection): void
{
    expect(Artisan::call('db:grant-app', ['--database' => $connection]))->toBe(0);
}

/** The SQL error code of a statement run as `app`, or null when it worked. */
function codeOf(string $sql): ?int
{
    try {
        DB::statement($sql);
    } catch (QueryException $e) {
        return (int) $e->errorInfo[1];
    }

    return null;
}

beforeEach(function () {
    $this->migrator = useMigratorConnection();
    foreach ([APPEND_ONLY_PROBE, PLAIN_PROBE] as $table) {
        DB::connection($this->migrator)->unprepared("DROP TABLE IF EXISTS `{$table}`");
        DB::connection($this->migrator)->unprepared("CREATE TABLE `{$table}` (n INT NOT NULL) ENGINE=InnoDB");
        DB::connection($this->migrator)->unprepared("INSERT INTO `{$table}` (n) VALUES (1)");
    }
});

afterEach(function () {
    $database = DB::connection($this->migrator)->getDatabaseName();

    foreach ([APPEND_ONLY_PROBE, PLAIN_PROBE] as $table) {
        DB::connection($this->migrator)->unprepared("DROP TABLE IF EXISTS `{$table}`");

        // A dropped table keeps the rights given on it: take them back.
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $right) {
            try {
                DB::connection($this->migrator)->unprepared("REVOKE {$right} ON `{$database}`.`{$table}` FROM 'app'@'%'");
            } catch (QueryException $e) {
                // "No such grant": nothing to take back.
            }
        }
    }
});

it('declares the five append-only tables of database.md section 6 [NFR-SEC-06]', function () {
    expect(config('database.append_only_tables'))
        ->toEqualCanonicalizing(['participations', 'votes', 'vote_choices', 'vote_audit', 'audit_entries']);
});

it('lets app insert but not update or delete in an append-only table, and do all three elsewhere [NFR-SEC-06]', function () {
    config(['database.append_only_tables' => [APPEND_ONLY_PROBE]]);

    grantRights($this->migrator);

    $append = APPEND_ONLY_PROBE;
    $plain = PLAIN_PROBE;

    expect(codeOf("INSERT INTO `{$append}` (n) VALUES (2)"))->toBeNull()
        ->and(DB::scalar("SELECT COUNT(*) FROM `{$append}`"))->toBe(2)
        ->and(codeOf("UPDATE `{$append}` SET n = 9"))->toBe(1142)
        ->and(codeOf("DELETE FROM `{$append}`"))->toBe(1142)
        ->and(codeOf("INSERT INTO `{$plain}` (n) VALUES (2)"))->toBeNull()
        ->and(codeOf("UPDATE `{$plain}` SET n = 9"))->toBeNull()
        ->and(codeOf("DELETE FROM `{$plain}`"))->toBeNull();

    // The grants themselves: INSERT alone on the append-only table.
    $grants = collect(DB::select('SHOW GRANTS FOR CURRENT_USER()'))->map(fn ($row) => (string) array_values((array) $row)[0]);
    $onAppend = $grants->first(fn (string $g) => str_contains($g, "`{$append}`"));

    expect($onAppend)->toContain('INSERT')->not->toContain('UPDATE')->not->toContain('DELETE');
});

it('never lets app change the schema or read the migrations table [NFR-SEC-06]', function () {
    grantRights($this->migrator);

    expect(codeOf('CREATE TABLE zz_probe_denied (n INT)'))->toBe(1142)
        ->and(codeOf('DROP TABLE '.PLAIN_PROBE))->toBe(1142)
        ->and(codeOf('ALTER TABLE '.PLAIN_PROBE.' ADD COLUMN m INT'))->toBe(1142);
});

it('takes the rights back when a table joins the list, and again when it leaves [NFR-SEC-06]', function () {
    $plain = PLAIN_PROBE;

    grantRights($this->migrator);
    expect(codeOf("UPDATE `{$plain}` SET n = 9"))->toBeNull();

    config(['database.append_only_tables' => [PLAIN_PROBE]]);
    grantRights($this->migrator);
    expect(codeOf("UPDATE `{$plain}` SET n = 8"))->toBe(1142)
        ->and(codeOf("DELETE FROM `{$plain}`"))->toBe(1142);

    config(['database.append_only_tables' => []]);
    grantRights($this->migrator);
    expect(codeOf("UPDATE `{$plain}` SET n = 7"))->toBeNull();
});
