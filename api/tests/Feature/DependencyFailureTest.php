<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * Only a database or Redis that cannot be reached, or does not answer in time, is a 503.
 * A fault of ours that looks alike is a 500.
 */

it('answers 500, not 503, when the database refuses our password [NFR-OPS-01]', function () {
    config(['database.connections.mariadb.password' => 'not-the-password']);
    DB::purge('mariadb');
    Route::get('/api/v1/_test/query', fn () => DB::select('select 1'));

    $this->getJson('/api/v1/_test/query')->assertStatus(500)->assertJsonPath('error.code', 'server_error');
});

it('answers 500 for an unrelated exception whose message says "Connection refused" [NFR-OPS-01]', function () {
    Route::get('/api/v1/_test/words', fn () => throw new RuntimeException('Connection refused by the payment provider'));

    $this->getJson('/api/v1/_test/words')->assertStatus(500)->assertJsonPath('error.code', 'server_error');
});

it('answers 503 for a database that cannot be reached [NFR-OPS-01]', function () {
    config(['database.connections.mariadb.host' => '127.0.0.1', 'database.connections.mariadb.port' => 1]);
    DB::purge('mariadb');
    Route::get('/api/v1/_test/query', fn () => DB::select('select 1'));

    $this->getJson('/api/v1/_test/query')->assertStatus(503)->assertJsonPath('error.code', 'dependency_unavailable');
});

it('answers 503 for a statement that runs past its time limit [NFR-OPS-01]', function () {
    Route::get('/api/v1/_test/slow', fn () => DB::select('SET STATEMENT max_statement_time=1 FOR SELECT SLEEP(5), 1 FROM information_schema.TABLES'));

    $started = microtime(true);
    $response = $this->getJson('/api/v1/_test/slow');

    $response->assertStatus(503)->assertJsonPath('error.code', 'dependency_unavailable');
    expect(microtime(true) - $started)->toBeLessThan(4.0);
});

it('puts a time limit on the health query [NFR-OPS-01]', function () {
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    $this->getJson('/api/v1/health')->assertOk();

    expect(implode("\n", $sql))->toContain('max_statement_time');
});

it('keeps a QueryException of another kind a 500 [NFR-OPS-01]', function () {
    Route::get('/api/v1/_test/syntax', fn () => DB::select('selec 1'));

    $this->getJson('/api/v1/_test/syntax')->assertStatus(500);
});
