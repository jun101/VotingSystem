<?php

use App\Support\Voting\ClientAddress;

it('keys an IPv6 client on its /64 prefix [NFR-SEC-05]', function () {
    $a = ClientAddress::bucket('2001:db8:1:2::1');

    expect(ClientAddress::bucket('2001:db8:1:2:aaaa:bbbb:cccc:dddd'))->toBe($a)
        ->and(ClientAddress::bucket('2001:DB8:1:2::ffff'))->toBe($a)
        ->and(ClientAddress::bucket('2001:db8:1:3::1'))->not->toBe($a)
        ->and(ClientAddress::bucket('2001:db8:2:2::1'))->not->toBe($a);
});

it('keys an IPv4 client on the address and an IPv4-mapped IPv6 one on the same address [NFR-SEC-05]', function () {
    expect(ClientAddress::bucket('10.0.0.1'))->toBe(hash('sha256', '10.0.0.1'))
        ->and(ClientAddress::bucket('::ffff:10.0.0.1'))->toBe(hash('sha256', '10.0.0.1'))
        ->and(ClientAddress::bucket('10.0.0.2'))->not->toBe(ClientAddress::bucket('10.0.0.1'));
});

it('hashes what is not an address and never returns the address itself [NFR-SEC-05]', function () {
    expect(ClientAddress::bucket(''))->toBe(hash('sha256', ''))
        ->and(ClientAddress::bucket('not an address'))->toBe(hash('sha256', 'not an address'))
        ->and(ClientAddress::bucket('2001:db8::1'))->toMatch('/\A[0-9a-f]{64}\z/');
});
