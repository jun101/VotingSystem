<?php

return [

    'default' => 'local',

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        // The pictures the application re-encodes (logos, later candidate photos). The root is a
        // volume shared with the proxy, which serves it read-only at `/media`; the files are
        // named after a random UUID, directly at the root. Public: the proxy may run as another user.
        'media' => [
            'driver' => 'local',
            'root' => env('MEDIA_ROOT', '/var/www/media'),
            'visibility' => 'public',
            'throw' => true,
            'report' => false,
        ],

    ],

    'links' => [],

];
