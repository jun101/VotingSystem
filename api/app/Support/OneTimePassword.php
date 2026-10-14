<?php

namespace App\Support;

use App\Support\Credentials\AccessCode;

/**
 * The password an owner hands over in school mode (no email): twelve characters of the access code alphabet
 * (no look-alikes), in three groups of four, from the secure random source. It is shown once and the person must
 * change it at the first sign-in.
 */
final class OneTimePassword
{
    public static function make(): string
    {
        $alphabet = AccessCode::ALPHABET;
        $last = strlen($alphabet) - 1;
        $groups = [];

        for ($group = 0; $group < 3; $group++) {
            $chars = '';

            for ($i = 0; $i < 4; $i++) {
                $chars .= $alphabet[random_int(0, $last)];
            }

            $groups[] = $chars;
        }

        return implode('-', $groups);
    }
}
