<?php

/*
 * NFR-SEC-08 — the scanner that guards every response of the test suite.
 * These tests check the guard itself.
 */

use Tests\Support\IdLeakScanner;

const SAMPLE_UUID = '6f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

it('accepts an id that is a version 4 UUID [NFR-SEC-08]', function () {
    expect(IdLeakScanner::findLeaks(['data' => ['id' => SAMPLE_UUID, 'title' => 'x']]))->toBe([]);
});

it('rejects a numeric id [NFR-SEC-08]', function () {
    expect(IdLeakScanner::findLeaks(['data' => ['id' => 42]]))->toHaveCount(1);
});

it('rejects a numeric id given as a string [NFR-SEC-08]', function () {
    expect(IdLeakScanner::findLeaks(['data' => ['id' => '42']]))->toHaveCount(1);
});

it('rejects a UUID that is not version 4 [NFR-SEC-08]', function () {
    // Version 7 starts with a timestamp: it reveals when the record was created.
    expect(IdLeakScanner::findLeaks(['id' => '018f2b6e-7c3a-7d1e-9a4b-3c5d6e7f8a9b']))->toHaveCount(1);
});

it('rejects a foreign-key style field, whatever it holds [NFR-SEC-08]', function () {
    expect(IdLeakScanner::findLeaks(['data' => ['election_id' => SAMPLE_UUID]]))->toHaveCount(1)
        ->and(IdLeakScanner::findLeaks(['data' => ['voter_ids' => []]]))->toHaveCount(1);
});

it('finds a leak at any depth and in lists [NFR-SEC-08]', function () {
    $body = ['data' => [
        ['id' => SAMPLE_UUID, 'ballots' => [['id' => SAMPLE_UUID], ['id' => 7]]],
    ]];

    expect(IdLeakScanner::findLeaks($body))->toHaveCount(1);
});

it('accepts a related record named by a field that carries its UUID [NFR-SEC-08]', function () {
    expect(IdLeakScanner::findLeaks(['data' => ['id' => SAMPLE_UUID, 'ballot' => SAMPLE_UUID]]))->toBe([]);
});

it('rejects a Location header with a numeric path segment [NFR-SEC-08]', function () {
    expect(IdLeakScanner::findLeaksInLocation('http://localhost/api/v1/elections/37'))->toHaveCount(1)
        ->and(IdLeakScanner::findLeaksInLocation('/api/v1/elections/'.SAMPLE_UUID))->toBe([])
        ->and(IdLeakScanner::findLeaksInLocation('/api/v1/elections'))->toBe([])
        ->and(IdLeakScanner::findLeaksInLocation(null))->toBe([]);
});

it('fails a test whose response leaks an id [NFR-SEC-08]', function () {
    Illuminate\Support\Facades\Route::get('/api/v1/_test/leak', fn () => response()->json(['data' => ['id' => 5]]));

    expect(fn () => $this->getJson('/api/v1/_test/leak'))
        ->toThrow(PHPUnit\Framework\ExpectationFailedException::class);
});
