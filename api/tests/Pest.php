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
