<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

// One connection, `mariadb`. In the api, queue and scheduler containers it is the account
// `app`: it reads and writes data but cannot change the schema. The migrations run in a
// container of their own (the `migrate` service of compose.yaml) where the same
// connection is the account `migrator`: the serving containers never hold that password.
$mariadb = [
    'driver' => 'mariadb',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE', 'votesystem'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'prefix_indexes' => true,
    'strict' => true,
    'engine' => null,
    // Short timeouts: the health check must answer fast when the database is down.
    'options' => [
        PDO::ATTR_TIMEOUT => 2,
        Mysql::ATTR_INIT_COMMAND => "SET SESSION wait_timeout = 60, time_zone = '+00:00'",
    ],
];

$redis = [
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'port' => env('REDIS_PORT', '6379'),
    'password' => env('REDIS_PASSWORD'),
    'timeout' => 2,
    'read_write_timeout' => 2,
];

return [

    'default' => 'mariadb',

    'connections' => [

        'mariadb' => $mariadb + [
            'username' => env('DB_USERNAME', 'app'),
            'password' => env('DB_PASSWORD'),
        ],

    ],

    // The account that `php artisan db:grant-app` gives its rights to (docs/design/database.md
    // section 6).
    'app_account' => [
        'username' => env('DB_APP_USERNAME', 'app'),
        'host' => '%',
    ],

    // Tables that only ever receive new rows: `app` has no UPDATE and no DELETE on them, so
    // a flaw in the application cannot change or remove a vote, a participation or an audit
    // entry (docs/design/database.md section 6). The one list: `db:grant-app` reads it.
    'append_only_tables' => [
        'participations',
        'votes',
        'vote_choices',
        'vote_audit',
        'audit_entries',
    ],

    // `app` may insert and update here, never delete: a user is removed with `deleted_at`
    // (docs/design/database.md section 1).
    'no_delete_tables' => [
        'institutions',
        'users',
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [

        'client' => 'predis',

        'options' => [
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
        ],

        'default' => $redis + ['database' => env('REDIS_DB', '0')],

        'cache' => $redis + ['database' => env('REDIS_CACHE_DB', '1')],

    ],

];
