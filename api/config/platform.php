<?php

return [

    // A platform admin without two-factor authentication gets 403 `two_factor_required` on every `/platform` route
    // (slice 19). On by default in production only: the development and test stacks sign admins in with a password.
    'require_two_factor' => filter_var(
        env('PLATFORM_REQUIRE_TWO_FACTOR', env('APP_ENV') === 'production'),
        FILTER_VALIDATE_BOOLEAN,
    ),

];
