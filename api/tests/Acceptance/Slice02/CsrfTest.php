<?php

/*
 * GET /api/v1/auth/csrf — docs/api/auth/GET-auth-csrf.md
 */

it('answers 204 and sets the CSRF cookie and the session cookie [NFR-SEC-04]', function () {
    $response = $this->browser->csrf();

    $response->assertNoContent();
    expect($response->getContent())->toBe('');

    $cookies = collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName());
    $session = $cookies->get(config('session.cookie'));
    $xsrf = $cookies->get('XSRF-TOKEN');

    expect($session)->not->toBeNull()
        ->and($session->isHttpOnly())->toBeTrue()
        ->and(strtolower((string) $session->getSameSite()))->toBe('lax')
        ->and($session->getPath())->toBe('/')
        ->and($xsrf)->not->toBeNull()
        ->and($xsrf->isHttpOnly())->toBeFalse()
        ->and(strtolower((string) $xsrf->getSameSite()))->toBe('lax')
        ->and($xsrf->getPath())->toBe('/');
});

it('keeps the session cookie for 120 minutes since the last request [NFR-SEC-04]', function () {
    expect((int) config('session.lifetime'))->toBe(120);

    $response = $this->browser->csrf();
    $session = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

    expect($session->getExpiresTime() - time())->toBeBetween(119 * 60, 121 * 60);
});

it('answers 429 above 60 requests a minute from one address [NFR-SEC-05]', function () {
    foreach (range(1, 60) as $i) {
        $this->browser->csrf()->assertNoContent();
    }

    $response = $this->browser->csrf();

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('answers 405 to another method than GET or HEAD [NFR-SEC-04]', function () {
    $response = $this->browser->other('POST', '/api/v1/auth/csrf');

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect($response->headers->get('Allow'))->toContain('GET');
});

it('leaves /health without a cookie [NFR-SEC-04]', function () {
    $response = $this->browser->get('/api/v1/health');

    expect($response->headers->getCookies())->toBe([]);
});
