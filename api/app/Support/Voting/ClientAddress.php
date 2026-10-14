<?php

namespace App\Support\Voting;

/**
 * The part of a client address a limit is counted on. An IPv4 address is itself; an IPv6 client
 * is its /64 prefix (one subscriber owns the whole /64, so a new address in it must not be a new
 * client); an IPv4-mapped IPv6 address is the IPv4 address. Hashed: the address never sits in a key.
 */
final class ClientAddress
{
    public static function bucket(string $address): string
    {
        $packed = filter_var($address, FILTER_VALIDATE_IP) === false ? false : inet_pton($address);

        if ($packed === false || strlen($packed) !== 16) {
            return hash('sha256', $address);
        }

        // ::ffff:a.b.c.d is the IPv4 client a.b.c.d.
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return hash('sha256', (string) inet_ntop(substr($packed, 12)));
        }

        return hash('sha256', 'v6:'.bin2hex(substr($packed, 0, 8)));
    }
}
