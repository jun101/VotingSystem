<?php

use App\Logging\RedactSensitiveFields;
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
