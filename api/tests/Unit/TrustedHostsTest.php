<?php

use App\Support\TrustedHosts;

it('trusts the host of APP_URL and the internal names, and no other [NFR-SEC-04]', function () {
    config(['app.url' => 'https://vote.example.org']);

    $patterns = TrustedHosts::patterns();

    $trusted = fn (string $host) => collect($patterns)->contains(fn (string $p) => preg_match('{'.$p.'}i', $host) === 1);

    expect($trusted('vote.example.org'))->toBeTrue()
        ->and($trusted('api'))->toBeTrue()
        ->and($trusted('proxy'))->toBeTrue()
        ->and($trusted('localhost'))->toBeTrue()
        ->and($trusted('evil.example'))->toBeFalse()
        ->and($trusted('vote.example.org.evil.example'))->toBeFalse()
        ->and($trusted('sub.vote.example.org'))->toBeFalse();
});
