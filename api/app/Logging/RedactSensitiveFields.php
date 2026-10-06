<?php

namespace App\Logging;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\QueryException;
use JsonSerializable;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Stringable;
use Throwable;

/**
 * Keeps secrets out of the log (NFR-OPS-04). Runs on every record, before the line is
 * written, on:
 *
 * - the fields of `context` and `extra`, at any depth: a field whose name holds a
 *   sensitive word is removed. The name is cut into words (`access_code`, `accessCode`,
 *   `Access-Code` are `access` and `code`) and each word is compared whole, so
 *   `voter_uuid` stays and `vote_audit` goes;
 * - the values that are not plain data: an exception, a model, a collection, anything
 *   convertible to an array is turned into an array and cleaned as well;
 * - the message, where `password=abc` or `"token": "abc"` is masked;
 * - a failed query: its SQL stays, the bound values never appear, in the message or in
 *   the database's own error text (which can quote one).
 */
class RedactSensitiveFields implements ProcessorInterface
{
    /** Words that make a field sensitive. Plurals are added below. */
    private const WORDS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'code', 'key', 'vote', 'ballot',
        'choice', 'credential', 'hash', 'signature', 'authorization', 'cookie', 'session',
        'binding',
    ];

    /** Whole names that hold a sensitive word but carry nothing secret. */
    private const HARMLESS = ['status_code', 'error_code', 'exit_code', 'http_code', 'cache_key'];

    private const MAX_DEPTH = 8;

    /** @var list<string>|null */
    private static ?array $words = null;

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;
        $exception = $context['exception'] ?? null;

        $message = $exception instanceof Throwable && $record->message === $exception->getMessage()
            ? $this->messageOf($exception)
            : $this->maskInText($record->message);

        return $record->with(
            message: $message,
            context: $this->clean($context, 0),
            extra: $this->clean($record->extra, 0),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function clean(array $data, int $depth): array
    {
        $kept = [];

        foreach ($data as $name => $value) {
            if (is_string($name) && self::isSensitive($name)) {
                continue;
            }

            $kept[$name] = $this->value($value, $depth + 1);
        }

        return $kept;
    }

    private function value(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '[too deep]';
        }

        return match (true) {
            is_array($value) => $this->clean($value, $depth),
            is_string($value) => $this->maskInText($value),
            $value instanceof Throwable => $this->throwable($value, $depth),
            $value instanceof Arrayable => $this->clean($value->toArray(), $depth),
            $value instanceof JsonSerializable => $this->value($value->jsonSerialize(), $depth),
            $value instanceof Stringable => $this->maskInText((string) $value),
            is_object($value) => ['class' => $value::class] + $this->clean(get_object_vars($value), $depth),
            default => $value,
        };
    }

    /** @return array<string, mixed> */
    private function throwable(Throwable $e, int $depth): array
    {
        $trace = [];
        foreach (array_slice($e->getTrace(), 0, 30) as $frame) {
            $trace[] = ($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'].' ('.($frame['file'] ?? '?').':'.($frame['line'] ?? '?').')';
        }

        $data = [
            'class' => $e::class,
            'message' => $this->messageOf($e),
            'code' => $e->getCode(),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace' => $trace,
        ];

        // The driver's text of a failed query can quote a bound value ("Duplicate entry
        // 'x@y'"): the exception it wraps is dropped, its SQLSTATE is in the message.
        if (($previous = $e->getPrevious()) !== null && ! $this->hidesBindings($e)) {
            $data['previous'] = $this->throwable($previous, $depth + 1);
        }

        return $data;
    }

    private function messageOf(Throwable $e): string
    {
        if ($this->hidesBindings($e)) {
            /** @var QueryException $e */
            return sprintf(
                'Query failed: SQLSTATE[%s], driver code %s (Connection: %s, SQL: %s)',
                $this->text($e->errorInfo[0] ?? null),
                $this->text($e->errorInfo[1] ?? null),
                $e->getConnectionName(),
                $e->getSql(),
            );
        }

        return $this->maskInText($e->getMessage());
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '?';
    }

    /** A query that was sent with values. */
    private function hidesBindings(Throwable $e): bool
    {
        return $e instanceof QueryException && $e->getBindings() !== [];
    }

    /** `password=abc`, `token: abc`, `"code":"abc"`, `Authorization: Bearer abc`. */
    private function maskInText(string $text): string
    {
        $words = implode('|', array_map(fn (string $w) => preg_quote($w, '/'), self::words()));

        return preg_replace(
            '/(?<![a-z])((?:[a-z]+[_-])*(?:'.$words.')(?:[_-][a-z]+)*["\']?\s*[=:]\s*)(?:Bearer\s+)?(?:"[^"]*"|\'[^\']*\'|[^\s,;&"\']+)/i',
            '$1[redacted]',
            $text,
        ) ?? $text;
    }

    private static function isSensitive(string $name): bool
    {
        // `accessCode` → `access_code`, `Access-Code` → `access_code`.
        $normal = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $name)));

        if (in_array($normal, self::HARMLESS, true)) {
            return false;
        }

        return array_intersect(explode('_', $normal), self::words()) !== [];
    }

    /** @return list<string> */
    private static function words(): array
    {
        return self::$words ??= array_values(array_unique(array_merge(
            self::WORDS,
            array_map(fn (string $w) => $w.'s', self::WORDS),
            array_map(fn (string $w) => $w.'es', self::WORDS),
            ['bindings', 'passwords'],
        )));
    }
}
