<?php

namespace Tests\Helpers;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One browser for the tests of slice 02 that need the cookies to travel: it keeps the cookies
 * the API sets, sends them back, and copies the XSRF-TOKEN cookie into the X-XSRF-TOKEN
 * header of every request that changes something. Two instances are two browsers.
 */
final class Browser
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(private readonly TestCase $test, private readonly string $ip = '127.0.0.1') {}

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /** @param  array<string, string>  $headers */
    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->send('GET', $uri, [], $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function post(string $uri, array $data = [], array $headers = [], bool $csrf = true): TestResponse
    {
        if ($csrf) {
            if (! isset($this->cookies['XSRF-TOKEN'])) {
                $this->get('/api/v1/auth/csrf');
            }

            $headers['X-XSRF-TOKEN'] = $this->cookies['XSRF-TOKEN'] ?? '';
        }

        return $this->send('POST', $uri, $data, $headers);
    }

    public function login(string $email, string $password): TestResponse
    {
        return $this->post('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $uri, array $data, array $headers): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->ip];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $response = $this->test->call($method, $uri, [], $this->cookies, [], $server, $method === 'GET' ? null : json_encode($data));

        foreach ($response->headers->getCookies() as $cookie) {
            $value = $cookie->getValue();

            if ($value === null || $value === '' || ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < time())) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = $value;
            }
        }

        return $response;
    }
}
