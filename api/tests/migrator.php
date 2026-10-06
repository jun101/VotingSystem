<?php

/*
 * The tests that create their own tables (in the test database only) need the `migrator`
 * account, which the application does not know. Its password is given to the one-off
 * `test` service of compose.yaml (`make test-api`) and to nothing that serves.
 */

/** Defines the connection `migrator` for the running test, and returns its name. */
function useMigratorConnection(): string
{
    $user = getenv('DB_MIGRATOR_USERNAME');
    $password = getenv('DB_MIGRATOR_PASSWORD');

    if (! is_string($user) || $user === '' || ! is_string($password) || $password === '') {
        throw new RuntimeException('The migrator account is not available: run the API tests with `make test-api` (the `test` service of compose.yaml).');
    }

    config(['database.connections.migrator' => [
        ...config('database.connections.mariadb'),
        'username' => $user,
        'password' => $password,
    ]]);

    return 'migrator';
}
