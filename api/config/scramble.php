<?php

// Generates docs/api/openapi.json from the routes and controllers (make generate).
// The documentation pages are not served (see AppServiceProvider): the file is the output.
return [

    'api_path' => 'api',

    'api_domain' => null,

    'export_path' => 'api.json',

    'info' => [
        'version' => '1.0.0',
        'description' => 'API of the New Voting System. Conventions: docs/api/README.md.',
    ],

    // A fixed, relative server so the generated file is the same on every machine.
    'servers' => ['Same origin' => '/api'],

    'middleware' => ['web'],

    'extensions' => [],

];
