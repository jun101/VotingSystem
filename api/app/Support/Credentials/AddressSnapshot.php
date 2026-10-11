<?php

namespace App\Support\Credentials;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * What a run keeps of the address it is to email a code to: a keyed hash, never the address. The job
 * compares it with the voter's address at send time and refuses a message whose address changed.
 */
final class AddressSnapshot
{
    /** The 32 raw bytes kept for an address (trimmed, lower case). */
    public static function of(string $address): string
    {
        $key = Config::get('credentials.hash_key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('The credential hash key is not set.');
        }

        return hash_hmac('sha256', 'address:'.mb_strtolower(trim($address)), $key, true);
    }
}
