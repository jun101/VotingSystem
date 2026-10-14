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

        // The uploaded voter files (slice 08). Private: not under `public`, not on a volume the
        // proxy reads. A file is named after a random UUID, directly at the root, and is read
        // back only by the application.
        'imports' => [
            'driver' => 'local',
            'root' => env('IMPORTS_ROOT', storage_path('app/private/imports')),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        // The slip PDFs of the access codes (slice 10). Private: each file is the PDF passed through
        // `Crypt::encryptString`, named after a random UUID with no extension, directly at the root.
        'credentials' => [
            'driver' => 'local',
            'root' => env('CREDENTIALS_ROOT', storage_path('app/private/credentials')),
            'visibility' => 'private',
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
