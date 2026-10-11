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

    /**
     * `codes-4e-annee.xlsx`, `codes-sans-groupe.csv`, `codes-tous.csv`: the file of a run for one group,
     * for the scope of the voters with no group, or `tous` for the others.
     */
    public static function codes(?string $groupName, string $extension, bool $ungrouped = false): string
    {
        $slug = $groupName === null ? ($ungrouped ? 'sans-groupe' : 'tous') : Str::slug(mb_substr($groupName, 0, 100));

        if ($slug === '') {
            $slug = 'groupe';
        }

        return 'codes-'.$slug.'.'.$extension;
    }
}
