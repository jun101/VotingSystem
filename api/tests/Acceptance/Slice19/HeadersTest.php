<?php

/*
 * NFR-SEC-04: the API's hardening headers, and the proxy files that carry the rest (docs/slices/19-security-hardening.md
 * "Rules"). The web CSP is checked in the browser suite (web/e2e/slice19/headers.spec.ts).
 */

use Tests\Support\AuthClient;
use Tests\Support\Team;
use Tests\Support\Voting;

it('puts the hardening headers on every kind of API answer [NFR-SEC-04]', function () {
    $t = Team::two();
    $e = Voting::election();
    $anonymous = new AuthClient($this);
    Team::signIn($this, $t['a']['owner']);

    $answers = [
        'health' => $anonymous->get('/api/v1/health'),
        'vote info' => $anonymous->get("/api/v1/vote/{$e['election']}"),
        'unauthenticated' => $anonymous->get('/api/v1/auth/me'),
        'me' => $this->browser->get('/api/v1/auth/me'),
        'list' => $this->browser->get('/api/v1/elections'),
        'not found' => $this->browser->get('/api/v1/elections/'.$e['election']),
        'method' => $this->browser->other('PUT', '/api/v1/elections'),
    ];

    foreach ($answers as $name => $response) {
        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff', $name)
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer', $name)
            ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; frame-ancestors 'none'", $name)
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    }
});

it('does not stop a file from being cached on purpose or downloaded [NFR-SEC-04]', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);
    $e = Voting::election(['institution' => $t['a']['owner']['institution'], 'status' => 'closed']);

    $response = $this->browser->get("/api/v1/elections/{$e['election']}/voters/export?format=csv")->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('keeps the development proxy free of HSTS (plain HTTP) and sets the same headers in both proxy files [NFR-SEC-04]', function () {
    $dev = file_get_contents(base_path('../docker/proxy/Caddyfile'));
    $production = file_get_contents(base_path('../docker/proxy/Caddyfile.production'));

    expect($dev)->not->toContain('Strict-Transport-Security')
        ->and($production)->toContain('Strict-Transport-Security "max-age=63072000; includeSubDomains"')
        ->and($production)->not->toContain('auto_https off');
    foreach ([$dev, $production] as $file) {
        expect($file)->toContain('Permissions-Policy')->toContain('X-Content-Type-Options nosniff')->toContain('Referrer-Policy')
            ->and($file)->toContain('-Server');
    }
});

it('refuses a CORS preflight from another origin [NFR-SEC-04]', function () {
    $response = $this->browser->other('OPTIONS', '/api/v1/auth/me');

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});
