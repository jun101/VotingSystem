<?php

/*
 * The conventions every endpoint shares — docs/api/README.md sections 1, 3, 4 and 5.
 */

use Illuminate\Support\Facades\Route;

it('sets a random X-Request-Id on every answer [NFR-OPS-04]', function () {
    $first = $this->getJson('/api/v1/health')->headers->get('X-Request-Id');
    $second = $this->getJson('/api/v1/health')->headers->get('X-Request-Id');

    expect($first)->toMatch(UUID_V4)
        ->and($second)->toMatch(UUID_V4)
        ->and($second)->not->toBe($first);
});

it('ignores an X-Request-Id sent by the client [NFR-OPS-04]', function () {
    $sent = '11111111-1111-4111-8111-111111111111';

    $response = $this->getJson('/api/v1/health', ['X-Request-Id' => $sent]);

    expect($response->headers->get('X-Request-Id'))->toMatch(UUID_V4)->not->toBe($sent);
});

it('answers 404 in the error shape for an unknown path [NFR-SEC-01]', function () {
    $response = $this->getJson('/api/v1/nothing-here');

    $response->assertStatus(404)->assertJsonPath('error.code', 'not_found');

    expect(array_keys($response->json()))->toBe(['error'])
        ->and(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message']);
});

it('answers JSON under /api whatever the Accept header says [NFR-SEC-01]', function () {
    $response = $this->get('/api/v1/nothing-here', ['Accept' => 'text/html']);

    $response->assertStatus(404)->assertJsonPath('error.code', 'not_found');

    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

it('answers 500 without any detail of the fault [NFR-OPS-04]', function () {
    config(['app.debug' => true]); // Even in debug mode nothing leaks.

    Route::get('/api/v1/_test/boom', function () {
        throw new RuntimeException('secret detail 4242');
    });

    $response = $this->getJson('/api/v1/_test/boom');

    $response->assertStatus(500)->assertJsonPath('error.code', 'server_error');

    expect(array_keys($response->json()))->toBe(['error'])
        ->and(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message', 'reference'])
        ->and($response->json('error.reference'))->toBe($response->headers->get('X-Request-Id'))
        ->and($response->getContent())->not->toContain('secret detail')
        ->and($response->getContent())->not->toContain('RuntimeException')
        ->and($response->getContent())->not->toContain('.php');
});
