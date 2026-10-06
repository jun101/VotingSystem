<?php

/*
 * Test bootstrap. The tests run inside the Compose stack, where the container already
 * defines the development settings as environment variables, and those win over any
 * file. So the test settings are written here, before Laravel starts, in every place
 * Laravel reads: they point at the test database and at Redis databases of their own,
 * so a test run never touches development data.
 */

$settings = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'DB_DATABASE' => (getenv('DB_DATABASE') ?: 'votesystem').'_test',
    'REDIS_DB' => '10',
    'REDIS_CACHE_DB' => '11',
    // The rate-limit counters live in memory so each test starts clean. Redis itself is
    // real: the health check pings it.
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'MAIL_MAILER' => 'array',
    'LOG_CHANNEL' => 'null',
];

foreach ($settings as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require __DIR__.'/../vendor/autoload.php';
