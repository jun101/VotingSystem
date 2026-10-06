<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

it('sets the request id on routes outside the api group too [NFR-OPS-04]', function () {
    Route::get('/outside', fn () => 'ok');

    $response = $this->get('/outside');

    $response->assertOk();
    expect($response->headers->get('X-Request-Id'))->toMatch(UUID_V4);
});

it('adds the request id to the context of every log line [NFR-OPS-04]', function () {
    Route::get('/api/v1/_test/context', fn () => ['seen' => Log::sharedContext()['request_id'] ?? null]);

    $response = $this->getJson('/api/v1/_test/context');

    expect($response->json('seen'))->toBe($response->headers->get('X-Request-Id'));
});

it('answers 404 with the request id also for a path outside the api [NFR-OPS-04]', function () {
    $response = $this->get('/nothing-here');

    $response->assertNotFound();
    expect($response->headers->get('X-Request-Id'))->toMatch(UUID_V4);
});
