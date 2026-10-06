<?php

return [

    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    // No batches and no failed-job table yet: slice 08 decides where failures are kept.
    'failed' => [
        'driver' => 'null',
    ],

];
