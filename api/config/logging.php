<?php

use App\Logging\RedactSensitiveFields;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    'default' => env('LOG_CHANNEL', 'stdout'),

    'deprecations' => [
        'channel' => 'null',
        'trace' => false,
    ],

    'channels' => [

        // One JSON object per line on standard output, collected by Docker. The request id
        // is added to every line by the RequestId middleware (Log::shareContext).
        'stdout' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stdout',
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
