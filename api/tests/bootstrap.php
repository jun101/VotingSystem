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
    // The default cache is in memory so each test starts clean. It cannot be Redis:
    // TestCase flushes the cache before every test, which would build the Redis manager
    // (and copy its settings) before HealthTest points `redis.default` at a dead port to
    // simulate an outage, and the manager never re-reads its settings. The Redis cache is
    // tested on its own: tests/Feature/RedisRateLimitTest.php. Redis itself is real: the
    // health check pings it.
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'MAIL_MAILER' => 'array',
    'LOG_CHANNEL' => 'null',
    // The rate limits of docs/api/auth/ are tested at their real value; the development stack
    // and the browser tests raise them (many sign-ups from one address).
    'AUTH_RATE_LIMIT_FACTOR' => '1',
];

foreach ($settings as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/migrator.php';
