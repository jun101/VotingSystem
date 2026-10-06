<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * Gives the `app` account its rights on the tables, one table at a time
 * (docs/design/database.md section 6). Run by the one-off `migrate` service after the
 * migrations, with the `migrator` account: the only account that may grant.
 *
 * - every table: `INSERT`, `UPDATE` and `DELETE`; `SELECT` is held on the whole database;
 * - the tables of `database.append_only_tables`: `INSERT` only (and `SELECT`);
 * - the migrations' own table: nothing.
 *
 * It can be run again at any time. The migrator cannot read another account's rights, so
 * it states what it wants instead: it grants what is allowed and revokes what is not,
 * ignoring "no such grant". The tables of the list are cleaned even before they exist,
 * so a table created later never inherits a right left behind.
 */
class GrantAppRights extends Command
{
    protected $signature = 'db:grant-app {--database= : The connection of the account that grants (the default one)}';

    protected $description = 'Give the app account its rights, table by table';

    /** MariaDB: "There is no such grant defined" (for a user, or for a user on a table). */
    private const NO_SUCH_GRANT = [1141, 1147];

    private const CHANGES_DATA = ['INSERT', 'UPDATE', 'DELETE'];

    public function handle(): int
    {
        $connection = DB::connection(is_string($this->option('database')) ? $this->option('database') : null);
        $pdo = $connection->getPdo();

        $account = $pdo->quote(Config::string('database.app_account.username')).'@'.$pdo->quote(Config::string('database.app_account.host'));
        $database = $this->identifier($connection->getDatabaseName());

        /** @var list<string> $appendOnly */
        $appendOnly = Config::array('database.append_only_tables');

        // The database level: reading everywhere, nothing that changes data.
        $this->revoke($pdo, self::CHANGES_DATA, "{$database}.*", $account);
        $pdo->exec("GRANT SELECT ON {$database}.* TO {$account}");

        $existing = [];
        foreach ($connection->select("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $row) {
            $name = ((array) $row)['name'] ?? null;
            if (is_string($name)) {
                $existing[] = $name;
            }
        }

        foreach ($existing as $table) {
            $on = "{$database}.".$this->identifier($table);

            if ($table === 'migrations') {
                $this->revoke($pdo, self::CHANGES_DATA, $on, $account);

                continue;
            }

            if (in_array($table, $appendOnly, true)) {
                $this->revoke($pdo, ['UPDATE', 'DELETE'], $on, $account);
                $pdo->exec("GRANT INSERT ON {$on} TO {$account}");
                $this->line(sprintf('%-24s INSERT', $table));
            } else {
                $pdo->exec("GRANT INSERT, UPDATE, DELETE ON {$on} TO {$account}");
                $this->line(sprintf('%-24s INSERT, UPDATE, DELETE', $table));
            }
        }

        // A table of the list that does not exist yet.
        foreach (array_diff($appendOnly, $existing) as $table) {
            $this->revoke($pdo, ['UPDATE', 'DELETE'], "{$database}.".$this->identifier($table), $account);
        }

        return self::SUCCESS;
    }

    /** @param  list<string>  $rights */
    private function revoke(PDO $pdo, array $rights, string $on, string $account): void
    {
        foreach ($rights as $right) {
            try {
                $pdo->exec("REVOKE {$right} ON {$on} FROM {$account}");
            } catch (PDOException $e) {
                if (! in_array($e->errorInfo[1] ?? null, self::NO_SUCH_GRANT, true)) {
                    throw $e;
                }
            }
        }
    }

    private function identifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }
}
