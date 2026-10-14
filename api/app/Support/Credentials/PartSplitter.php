<?php

namespace App\Support\Credentials;

/** Cuts the voters of a group into files of at most 1 000 slips. */
final class PartSplitter
{
    public const SIZE = 1000;

    /**
     * The voter count of each part, in order: 2 500 gives [1000, 1000, 500]; 0 gives [].
     *
     * @return list<int>
     */
    public static function sizes(int $count): array
    {
        $sizes = [];

        for ($left = max(0, $count); $left > 0; $left -= self::SIZE) {
            $sizes[] = min($left, self::SIZE);
        }

        return $sizes;
    }
}
