<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\IdLeakScanner;

/**
 * Base class of every test.
 *
 * Part of the acceptance harness: it is not edited when a slice is coded.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rate-limit counters must not carry over from one test to the next.
        Cache::flush();
    }

    /**
     * Every response of every test is scanned: no internal numeric identifier may
     * leave the server (SPEC NFR-SEC-08).
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        IdLeakScanner::assertClean($response, $method.' '.$uri);

        return $response;
    }
}
