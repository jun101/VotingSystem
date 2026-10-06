<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

// Two accounts on the same database. `mariadb` (account `app`) is what the application,
// the queue worker and the scheduler use: it reads and writes data but cannot change
// the schema. `migrator` runs the migrations and nothing else.
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

        'migrator' => $mariadb + [
            'username' => env('DB_MIGRATOR_USERNAME', 'migrator'),
            'password' => env('DB_MIGRATOR_PASSWORD'),
        ],

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
