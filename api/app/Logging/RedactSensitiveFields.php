<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Removes from a log record every field whose name looks like a secret: a password, an
 * access code, a token, a key, a vote (NFR-OPS-04). Runs on `context` and `extra`, at
 * any depth, before the line is written.
 */
class RedactSensitiveFields implements ProcessorInterface
{
    private const PATTERN = '/password|passwd|secret|token|code|key|vote/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->clean($record->context),
            extra: $this->clean($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function clean(array $data): array
    {
        $kept = [];

        foreach ($data as $name => $value) {
            if (is_string($name) && preg_match(self::PATTERN, $name) === 1) {
                continue;
            }

            $kept[$name] = is_array($value) ? $this->clean($value) : $value;
        }

        return $kept;
    }
}
