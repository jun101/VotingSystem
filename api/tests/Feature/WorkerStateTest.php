<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
 * Octane keeps one application in memory for many requests. These tests send several
 * requests through the same application instance, as a worker does, and check that
 * nothing request-specific carries over.
 */

beforeEach(function () {
    Route::get('/api/v1/_test/state', fn () => [
        'locale' => app()->getLocale(),
        'context_keys' => array_keys(Log::sharedContext()),
        'rid' => Log::sharedContext()['request_id'] ?? null,
    ]);
});

it('does not carry the language of one request into the next [NFR-UX-01]', function () {
    // The first request asks for English and fails, so the renderer switches the language.
    $this->postJson('/api/v1/health', [], ['Accept-Language' => 'en'])
        ->assertJsonPath('error.message', 'Method not allowed.');

    expect(app()->getLocale())->toBe('en');

    // The next request has no error: it must start again from the default language.
    $this->getJson('/api/v1/_test/state')->assertJsonPath('locale', 'fr');
});

it('does not carry the request id or the log context into the next request [NFR-OPS-04]', function () {
    $first = $this->getJson('/api/v1/_test/state');
    Log::shareContext(['leftover' => 'from the first request']);
    $second = $this->getJson('/api/v1/_test/state');

    expect($second->json('context_keys'))->toBe(['request_id'])
        ->and($second->json('rid'))->toBe($second->headers->get('X-Request-Id'))
        ->and($second->headers->get('X-Request-Id'))->not->toBe($first->headers->get('X-Request-Id'));
});
