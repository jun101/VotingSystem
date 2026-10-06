<?php

/*
 * GET /api/v1/health — docs/api/system/GET-health.md
 * One test per scenario of that file.
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('answers 200 with the state of the database and Redis [NFR-OPS-01]', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.checks', ['database' => 'ok', 'redis' => 'ok']);

    // Exactly the documented fields: nothing about versions, hosts or configuration.
    expect(array_keys($response->json()))->toBe(['data'])
        ->and(array_keys($response->json('data')))->toEqualCanonicalizing(['status', 'checks', 'time']);

    // The time is read from the database, in UTC, to the second.
    $time = $response->json('data.time');
    expect($time)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and(abs(Carbon::parse($time)->diffInSeconds(now(), false)))->toBeLessThan(60);
});

it('answers 503 when the database does not answer [NFR-OPS-01]', function () {
    $connection = DB::getDefaultConnection();
    config([
        "database.connections.{$connection}.url" => null,
        "database.connections.{$connection}.host" => '127.0.0.1',
        "database.connections.{$connection}.port" => 1,
    ]);
    DB::purge($connection);

    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(503)->assertJsonPath('error.code', 'dependency_unavailable');

    expect($response->json('error.reference'))->toBe($response->headers->get('X-Request-Id'))
        ->and(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message', 'reference'])
        ->and($response->getContent())->not->toContain('SQLSTATE')
        ->and($response->getContent())->not->toContain('127.0.0.1');
});

it('answers 503 when Redis does not answer [NFR-OPS-01]', function () {
    config([
        'database.redis.default.url' => null,
        'database.redis.default.host' => '127.0.0.1',
        'database.redis.default.port' => 1,
    ]);
    Redis::purge('default');

    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(503)->assertJsonPath('error.code', 'dependency_unavailable');

    expect(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message', 'reference'])
        ->and($response->getContent())->not->toContain('127.0.0.1');
});

it('answers 405 to another method [NFR-OPS-01]', function () {
    $response = $this->postJson('/api/v1/health');

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');

    expect($response->headers->get('Allow'))->toContain('GET');
});

it('answers 429 above the rate limit [NFR-SEC-05]', function () {
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/v1/health')->assertOk();
    }

    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('writes the message in the language asked for [NFR-UX-01]', function () {
    $french = $this->postJson('/api/v1/health', [], ['Accept-Language' => 'fr'])->json('error.message');
    $english = $this->postJson('/api/v1/health', [], ['Accept-Language' => 'en'])->json('error.message');
    // The test client always sends an Accept-Language header, so "no header" cannot be
    // tested from here. A language we do not have must fall back to French.
    $other = $this->postJson('/api/v1/health', [], ['Accept-Language' => 'de'])->json('error.message');
    $regional = $this->postJson('/api/v1/health', [], ['Accept-Language' => 'en-US,en;q=0.9'])->json('error.message');

    expect($french)->toBeString()->not->toBe('')
        ->and($english)->toBeString()->not->toBe('')
        ->and($english)->not->toBe($french)
        ->and($other)->toBe($french)
        ->and($regional)->toBe($english);
});
