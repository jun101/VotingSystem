<?php

use App\Logging\RedactSensitiveFields;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    'default' => env('LOG_CHANNEL', 'stderr'),

    'deprecations' => [
        'channel' => 'null',
        'trace' => false,
    ],

    'channels' => [

        // One JSON object per line, collected by Docker (`docker compose logs api`). It is
        // written to standard ERROR on purpose: Octane (FrankenPHP) throws away what a
        // worker writes to standard output, and relays standard error. The `api` service
        // starts Octane with `--log-level`, which passes those lines through untouched.
        // The request id is added to every line by AssignRequestId (Log::shareContext).
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                'appendNewline' => true,
            ],
            'processors' => [
                PsrLogMessageProcessor::class,
                RedactSensitiveFields::class,
            ],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

    ],

];
