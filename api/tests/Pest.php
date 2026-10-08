<?php

/*
 * Part of the acceptance harness: it is not edited when a slice is coded.
 *
 * Acceptance: written before the code, from docs/api/. One test per scenario.
 * Feature, Unit: written with the code.
 *
 * All suites run against a real MariaDB and a real Redis, inside the Compose stack.
 */

pest()->extend(Tests\TestCase::class)->in('Acceptance', 'Feature', 'Unit');

const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

// Slice 02 and after: a clean database and one browser (docs/api/auth/).
pest()->beforeEach(function () {
    Tests\Support\Accounts::reset();
    $this->browser = new Tests\Support\AuthClient($this);
})->in('Acceptance/Slice02');

// Slice 03: the same, and the test-only tenant tables (docs/slices/03-admin-shell-and-tenant-isolation.md).
pest()->beforeEach(function () {
    Tests\Support\Accounts::reset();
    Tests\Support\Tenancy::installProbeTables();
    $this->browser = new Tests\Support\AuthClient($this);
})->in('Acceptance/Slice03');

// Slice 04: the same, and an empty `media` disk where the logos are written
// (docs/slices/04-profile-and-users.md).
pest()->beforeEach(function () {
    Tests\Support\Accounts::reset();
    Tests\Support\Tenancy::installProbeTables();
    Tests\Support\Team::clearMedia();
    $this->browser = new Tests\Support\AuthClient($this);
})->in('Acceptance/Slice04');

// Slice 04b: the same (docs/slices/04b-two-factor.md).
pest()->beforeEach(function () {
    Tests\Support\Accounts::reset();
    Tests\Support\Tenancy::installProbeTables();
    $this->browser = new Tests\Support\AuthClient($this);
})->in('Acceptance/Slice04b');
