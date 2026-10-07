<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One browser talking to the API: it keeps the cookies the API sets and sends them back,
 * and it copies the XSRF-TOKEN cookie into the X-XSRF-TOKEN header on every request that
 * changes something, as the web application does (docs/api/auth/GET-auth-csrf.md).
 *
 * Two clients in one test are two browsers.
 *
 * Part of the acceptance harness: it is not edited when a slice is coded.
 */
final class AuthClient
{
    /** @var array<string, string> cookie name => raw value, as the API sent it */
    private array $cookies = [];

    /** @var array<string, string> */
    private array $server = [];

    public function __construct(private readonly TestCase $test) {}

    /** The address the API sees for this browser (the rate limits count per address). */
    public function fromAddress(string $ip): self
    {
        $this->server['REMOTE_ADDR'] = $ip;

        return $this;
    }

    /**
     * Sends no Accept-Language at all. The test framework adds one to every request, so
     * this empties it, as a client that does not send the header.
     */
    public function withoutAcceptLanguage(): self
    {
        $this->server['HTTP_ACCEPT_LANGUAGE'] = '';

        return $this;
    }

    public function csrf(): TestResponse
    {
        return $this->get('/api/v1/auth/csrf');
    }

    public function hasCookie(string $name): bool
    {
        return isset($this->cookies[$name]);
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /** Forgets every cookie, as a browser that was closed and cleaned. */
    public function forgetCookies(): void
    {
        $this->cookies = [];
    }

    /** Removes one cookie only. */
    public function forgetCookie(string $name): void
    {
        unset($this->cookies[$name]);
    }

    /** @param array<string, string> $headers */
    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->send('GET', $uri, null, $headers);
    }

    /**
     * A request that changes something. By default the CSRF cookie is fetched first when the
     * browser has none, and the header is sent; `$csrf = false` sends neither.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function post(string $uri, array $data = [], array $headers = [], bool $csrf = true): TestResponse
    {
        if ($csrf) {
            if (! $this->hasCookie('XSRF-TOKEN')) {
                $this->csrf();
            }
            $headers['X-XSRF-TOKEN'] = $this->cookies['XSRF-TOKEN'] ?? '';
        }

        return $this->send('POST', $uri, $data, $headers);
    }

    /** A POST whose body is sent as it is (to send what is not valid JSON). */
    public function postRaw(string $uri, string $body): TestResponse
    {
        if (! $this->hasCookie('XSRF-TOKEN')) {
            $this->csrf();
        }

        return $this->remember($this->test
            ->withServerVariables($this->server)
            ->call('POST', $uri, [], $this->cookies, [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_XSRF_TOKEN' => $this->cookies['XSRF-TOKEN'] ?? '',
            ], $body));
    }

    /** Any method, for the "method not allowed" scenarios. */
    public function other(string $method, string $uri): TestResponse
    {
        return $this->send($method, $uri, [], $method === 'GET' ? [] : ['X-XSRF-TOKEN' => $this->cookies['XSRF-TOKEN'] ?? '']);
    }

    /** Signs in through the API; the browser is then signed in. */
    public function login(string $email, string $password): TestResponse
    {
        return $this->post('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $uri, ?array $data, array $headers): TestResponse
    {
        // `json()` sends cookies only with credentials; and the test case keeps the cookies it
        // was given, so they are reset first: two clients in one test are two browsers.
        (function () {
            $this->unencryptedCookies = [];
        })->call($this->test);

        return $this->remember($this->test
            ->withUnencryptedCookies($this->cookies)
            ->withCredentials()
            ->withServerVariables($this->server)
            ->json($method, $uri, $data ?? [], $headers));
    }

    private function remember(TestResponse $response): TestResponse
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $gone = $cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < time();

            if ($gone || $cookie->getValue() === null || $cookie->getValue() === '') {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }
}
