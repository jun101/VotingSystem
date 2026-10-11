<?php

namespace App\Support\Credentials;

use Illuminate\Support\Str;

/** The name a downloaded file is given: a lower-case ASCII slug, never a UUID or a numeric id. */
final class SlipFileName
{
    /** `coupons-4e-annee.pdf`, `coupons-sans-groupe.pdf`, `coupons-4e-annee-partie-2.pdf`. */
    public static function for(?string $groupName, int $part, int $parts): string
    {
        $slug = $groupName === null ? 'sans-groupe' : Str::slug(mb_substr($groupName, 0, 100));

        if ($slug === '') {
            $slug = 'groupe';
        }

        return 'coupons-'.$slug.($parts > 1 ? '-partie-'.$part : '').'.pdf';
    }
}
