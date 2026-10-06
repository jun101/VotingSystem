<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('answers 422 with the rule codes of each field [NFR-SEC-01]', function () {
    Route::post('/api/v1/_test/validate', function (Request $request) {
        $request->validate(['title' => ['required'], 'count' => ['integer', 'min:1']]);
    });

    $response = $this->postJson('/api/v1/_test/validate', ['count' => 'x']);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    expect($response->json('error.fields'))->toBe(['title' => ['required'], 'count' => ['integer']])
        ->and(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message', 'fields']);
});

it('answers 401 for an unauthenticated exception [NFR-SEC-01]', function () {
    Route::get('/api/v1/_test/private', fn () => throw new AuthenticationException);

    $this->getJson('/api/v1/_test/private')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('maps an HTTP status of the conventions to its code [NFR-SEC-01]', function (int $status, string $code) {
    Route::get('/api/v1/_test/status', fn () => throw new HttpException($status));

    $this->getJson('/api/v1/_test/status')->assertStatus($status)->assertJsonPath('error.code', $code);
})->with([
    [403, 'forbidden'],
    [409, 'conflict'],
    [410, 'expired'],
    [413, 'file_too_large'],
    [415, 'file_type_not_allowed'],
    [419, 'csrf_mismatch'],
]);

it('answers 500 for a status the conventions do not list [NFR-SEC-01]', function () {
    Route::get('/api/v1/_test/teapot', fn () => throw new HttpException(418));

    $this->getJson('/api/v1/_test/teapot')->assertStatus(500)->assertJsonPath('error.code', 'server_error');
});

it('keeps a response built on purpose by the code that threw [NFR-SEC-01]', function () {
    Route::get('/api/v1/_test/custom', fn () => throw new HttpResponseException(response()->json(['custom' => true], 202)));

    $this->getJson('/api/v1/_test/custom')->assertStatus(202)->assertExactJson(['custom' => true]);
});

it('answers 503 for a service that fails in the middle of a request, not only in the health check [NFR-OPS-01]', function () {
    config(['database.connections.mariadb.host' => '127.0.0.1', 'database.connections.mariadb.port' => 1]);
    DB::purge('mariadb');
    Route::get('/api/v1/_test/query', fn () => DB::table('anything')->count());

    $response = $this->getJson('/api/v1/_test/query');

    $response->assertStatus(503)->assertJsonPath('error.code', 'dependency_unavailable');
    expect($response->headers->get('Retry-After'))->toBe('5');
});

it('does not turn a validation exception raised by hand into a 500 [NFR-SEC-01]', function () {
    Route::get('/api/v1/_test/by-hand', fn () => throw ValidationException::withMessages(['email' => 'bad']));

    $this->getJson('/api/v1/_test/by-hand')->assertStatus(422);
});
