<?php

/*
 * No path allows a cross-origin request. The web application and the API are served from
 * one origin (the proxy), so no browser needs permission to call across origins, and no
 * `Access-Control-*` header is ever sent. Without this file the framework's defaults
 * would allow every origin on every `/api` path.
 */
return [

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
