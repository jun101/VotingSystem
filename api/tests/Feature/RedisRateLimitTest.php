<?php

use App\Providers\AppServiceProvider;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Redis;

/*
 * The rate limiter runs on the Redis cache in production. The other tests use the
 * in-memory cache (tests/bootstrap.php says why); this one builds the limiter on Redis, on its
 * database of its own (REDIS_CACHE_DB).
 */

beforeEach(function () {
    // The limiter is built once at start, on the cache of that moment: build it again on
    // Redis and declare ours again.
    config(['cache.limiter' => 'redis']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    app()->getProvider(AppServiceProvider::class)->boot();
    Redis::connection('cache')->flushdb();
});

afterEach(fn () => Redis::connection('cache')->flushdb());

it('counts the requests of an address in Redis and answers 429 above the limit [NFR-SEC-05]', function () {
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/v1/health')->assertOk();
    }

    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        // The counter really is in Redis: the database holds the limiter's keys.
        ->and(Redis::connection('cache')->dbsize())->toBeGreaterThan(0);
});
