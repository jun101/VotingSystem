<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
 * The log is written through the real channel configuration (config/logging.php), only
 * with its stream swapped for memory. This is what the other logging tests do not do:
 * they read the shared context, never a written line. It is how a channel that wrote to
 * a stream Octane throws away went unseen.
 */

/** Points the application's default log channel at memory; returns what to read it with. */
function logToMemory(): Closure
{
    $stream = fopen('php://memory', 'w+');

    config([
        'logging.default' => 'stderr',
        'logging.channels.stderr.level' => 'debug',
        'logging.channels.stderr.handler_with.stream' => $stream,
    ]);
    Log::forgetChannel('stderr');
    Log::setDefaultDriver('stderr');

    return function () use ($stream): array {
        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));

        return array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
    };
}

it('sends the log to standard error, where Octane relays it [NFR-OPS-04]', function () {
    expect(config('logging.channels.stderr.handler_with.stream'))->toBe('php://stderr');
});

it('writes one JSON line per record, with the request id of the request [NFR-OPS-04]', function () {
    $lines = logToMemory();
    Route::get('/api/v1/_test/log', function () {
        Log::info('something happened', ['voter_uuid' => 'a-uuid', 'status_code' => 200]);

        return ['ok' => true];
    });

    $response = $this->getJson('/api/v1/_test/log');
    $written = $lines();

    expect($written)->toHaveCount(1)
        ->and($written[0]['message'])->toBe('something happened')
        ->and($written[0]['context']['request_id'])->toBe($response->headers->get('X-Request-Id'))
        // Whole words only: these names hold "vote", "code" without being secret.
        ->and($written[0]['context']['voter_uuid'])->toBe('a-uuid')
        ->and($written[0]['context']['status_code'])->toBe(200);
});

it('redacts secrets in what it writes, and logs a failed query without its bindings [NFR-OPS-04]', function () {
    $lines = logToMemory();
    Route::get('/api/v1/_test/log-secrets', function () {
        Log::warning('sign-in with password=hunter2', ['password' => 'hunter2', 'user' => ['name' => 'Ada', 'token' => 'abc']]);

        return DB::select('select * from a_table_that_does_not_exist where code = ?', ['code-4242']);
    });

    $response = $this->getJson('/api/v1/_test/log-secrets');
    $written = $lines();
    $text = json_encode($written);

    $response->assertStatus(500)->assertJsonPath('error.code', 'server_error');

    expect($written)->toHaveCount(2)
        // The lines are the ones of this request.
        ->and($written[0]['context']['request_id'])->toBe($response->headers->get('X-Request-Id'))
        ->and($written[1]['context']['request_id'])->toBe($response->headers->get('X-Request-Id'))
        ->and($written[0]['message'])->toBe('sign-in with password=[redacted]')
        ->and($written[0]['context']['user'])->toBe(['name' => 'Ada'])
        ->and($written[0]['context'])->not->toHaveKey('password')
        // The failed query: its SQL stays, the bound value does not appear anywhere.
        ->and($written[1]['message'])->toContain('a_table_that_does_not_exist')
        ->and($text)->not->toContain('hunter2')
        ->and($text)->not->toContain('code-4242')
        ->and($text)->not->toContain('abc');
});
