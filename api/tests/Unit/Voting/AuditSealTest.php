<?php

use App\Support\Voting\AuditKeyUnusable;
use App\Support\Voting\AuditSeal;
use Ramsey\Uuid\Uuid;

it('builds the 89-byte content of an audit record in the documented order [FR-SEC-08]', function () {
    $ids = array_map(fn () => Uuid::uuid4()->toString(), range(1, 5));

    $content = AuditSeal::content(...$ids, at: 1_790_000_000);

    expect(strlen($content))->toBe(AuditSeal::CONTENT_BYTES)
        ->and(ord($content[0]))->toBe(1)
        ->and(Uuid::fromBytes(substr($content, 1, 16))->toString())->toBe($ids[0])
        ->and(Uuid::fromBytes(substr($content, 17, 16))->toString())->toBe($ids[1])
        ->and(Uuid::fromBytes(substr($content, 33, 16))->toString())->toBe($ids[2])
        ->and(Uuid::fromBytes(substr($content, 49, 16))->toString())->toBe($ids[3])
        ->and(Uuid::fromBytes(substr($content, 65, 16))->toString())->toBe($ids[4])
        ->and(unpack('J', substr($content, 81, 8))[1])->toBe(1_790_000_000)
        ->and(bin2hex(substr($content, 81, 8)))->toBe('00000000'.dechex(1_790_000_000));
});

it('seals the content to 137 bytes that only the private key opens [FR-SEC-08]', function () {
    $pair = sodium_crypto_box_keypair();
    $content = AuditSeal::content(...array_map(fn () => Uuid::uuid4()->toString(), range(1, 5)), at: 1_790_000_000);

    $sealed = AuditSeal::seal($content, AuditSeal::publicKey(bin2hex(sodium_crypto_box_publickey($pair))));

    expect(strlen($sealed))->toBe(AuditSeal::SEALED_BYTES)
        ->and(sodium_crypto_box_seal_open($sealed, $pair))->toBe($content)
        ->and(sodium_crypto_box_seal_open($sealed, sodium_crypto_box_keypair()))->toBeFalse()
        // Two seals of one content differ: a fresh one-time key each time.
        ->and(AuditSeal::seal($content, sodium_crypto_box_publickey($pair)))->not->toBe($sealed);
});

it('refuses a key that is missing, short, long or not hexadecimal without echoing it [FR-SEC-02]', function (mixed $key) {
    expect(fn () => AuditSeal::publicKey($key))->toThrow(AuditKeyUnusable::class);
})->with([
    'null' => [null],
    'empty' => [''],
    'short' => ['abcd'],
    'long' => [str_repeat('a', 66)],
    'not hex' => [str_repeat('zz', 32)],
    'not a string' => [12345],
]);

it('never puts the key in the message of its exception [NFR-SEC-02]', function () {
    $secret = 'deadbeef-not-a-key';

    try {
        AuditSeal::publicKey($secret);
    } catch (AuditKeyUnusable $e) {
        expect($e->getMessage())->not->toContain($secret);
    }
});

it('accepts a 64-character key in either case [FR-SEC-08]', function () {
    $hex = bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair()));

    expect(strlen(AuditSeal::publicKey($hex)))->toBe(32)
        ->and(AuditSeal::publicKey(strtoupper($hex)))->toBe(AuditSeal::publicKey($hex));
});
