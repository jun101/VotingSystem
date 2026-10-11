<?php

namespace App\Support\Voting;

/**
 * What a voter typed, as the code it stands for: spaces and hyphens ignored, upper case, and cut to
 * a length no code reaches, so the work done for a long string is the work done for a short one.
 * A string that is not a code stays a string that matches nothing: it is hashed and looked up all the
 * same (docs/api/vote/POST-vote-{election}-session.md).
 */
final class TypedCode
{
    /** More than the ten characters of a code, so that a code with extra characters still does not match. */
    public const MAX = 64;

    public static function normalise(string $typed): string
    {
        return strtoupper(substr((string) preg_replace('/[\s-]+/u', '', $typed), 0, self::MAX));
    }
}
