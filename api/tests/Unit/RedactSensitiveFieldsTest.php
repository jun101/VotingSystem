<?php

use App\Logging\RedactSensitiveFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Monolog\Level;
use Monolog\LogRecord;

function redacted(array $context, array $extra = []): LogRecord
{
    $record = new LogRecord(new DateTimeImmutable, 'test', Level::Info, 'message', $context, $extra);

    return (new RedactSensitiveFields)($record);
}

it('removes the fields named like a secret [NFR-OPS-04]', function (string $name) {
    $record = redacted([$name => 'value', 'request_id' => 'kept']);

    expect($record->context)->toBe(['request_id' => 'kept']);
})->with(['password', 'new_password', 'access_code', 'Code', 'api_token', 'secret', 'vote_audit_key', 'choices_vote']);

it('removes them at any depth and in the extra data [NFR-OPS-04]', function () {
    $record = redacted(
        ['user' => ['name' => 'Ada', 'password' => 'x', 'tags' => [['code' => '1'], ['label' => 'ok']]]],
        ['token' => 'x', 'host' => 'api'],
    );

    expect($record->context)->toBe(['user' => ['name' => 'Ada', 'tags' => [[], ['label' => 'ok']]]])
        ->and($record->extra)->toBe(['host' => 'api']);
});

it('leaves the message and harmless fields alone [NFR-OPS-04]', function () {
    $record = redacted(['method' => 'GET', 'status' => 200]);

    expect($record->message)->toBe('message')
        ->and($record->context)->toBe(['method' => 'GET', 'status' => 200]);
});

it('matches whole words: harmless names stay, new sensitive words go [NFR-OPS-04]', function () {
    $record = redacted([
        'voter_uuid' => 'u', 'status_code' => 200, 'error_code' => 'x', 'cache_key' => 'k', 'keyboard' => 'k',
        'authorization' => 'Bearer x', 'Cookie' => 'c', 'session_id' => 's', 'credentials' => 'c', 'ballot' => 'b',
        'choice_uuid' => 'c', 'password_hash' => 'h', 'signature' => 's', 'accessCode' => '1', 'X-Api-Key' => 'k',
        'bindings' => [1], 'votes' => [], 'request_id' => 'r',
    ]);

    expect(array_keys($record->context))->toBe(['voter_uuid', 'status_code', 'error_code', 'cache_key', 'keyboard', 'request_id']);
});

it('masks a secret written in the message [NFR-OPS-04]', function (string $message, string $expected) {
    $record = new LogRecord(new DateTimeImmutable, 'test', Level::Info, $message, []);

    expect((new RedactSensitiveFields)($record)->message)->toBe($expected);
})->with([
    ['login password=hunter2 failed', 'login password=[redacted] failed'],
    ['{"token": "abc def", "name": "Ada"}', '{"token": [redacted], "name": "Ada"}'],
    ['GET /x?access_code=1234&page=2', 'GET /x?access_code=[redacted]&page=2'],
    ['Authorization: Bearer abc123', 'Authorization: [redacted]'],
    ['nothing to hide here', 'nothing to hide here'],
]);

it('cleans what is not plain data: an object, a model, a collection, an exception [NFR-OPS-04]', function () {
    $model = (new class extends Model
    {
        protected $guarded = [];
    })->forceFill(['name' => 'Ada', 'password' => 'x']);

    $record = redacted([
        'user' => $model,
        'list' => collect([['name' => 'a', 'token' => 't']]),
        'object' => (object) ['name' => 'o', 'secret' => 's'],
        'exception' => new RuntimeException('failed with token=abc'),
    ]);

    expect($record->context['user'])->toBe(['name' => 'Ada'])
        ->and($record->context['list'])->toBe([['name' => 'a']])
        ->and($record->context['object'])->toBe(['class' => 'stdClass', 'name' => 'o'])
        ->and($record->context['exception']['class'])->toBe(RuntimeException::class)
        ->and($record->context['exception']['message'])->toBe('failed with token=[redacted]');
});

it('logs a failed query without its bound values, in the message and in the context [NFR-OPS-04]', function () {
    $driver = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'ada@example.org' for key 'users_email_unique'");
    $driver->errorInfo = ['23000', 1062, 'Duplicate entry'];
    $query = new QueryException('mariadb', 'insert into users (email) values (?)', ['ada@example.org'], $driver);

    $record = new LogRecord(new DateTimeImmutable, 'test', Level::Error, $query->getMessage(), ['exception' => $query]);
    $record = (new RedactSensitiveFields)($record);

    expect(json_encode($record))->not->toContain('ada@example.org')
        ->and($record->message)->toContain('insert into users (email) values (?)')
        ->and($record->message)->toContain('23000')
        ->and($record->context['exception'])->not->toHaveKey('previous');
});
