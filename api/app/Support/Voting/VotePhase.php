<?php

namespace App\Support\Voting;

use App\Enums\ElectionStatus;
use DateTimeInterface;

/**
 * Whether an election can be voted in, from its status AND the server's clock (FR-ELEC-05,
 * docs/api/vote/GET-vote-{election}.md). The status alone is never trusted: an election that is
 * still `open` after its end is closed, and one that is `open` before its start is not yet open.
 *
 * Stateless: the time is given by the caller (the application clock, in UTC).
 */
final class VotePhase
{
    public const BEFORE = 'before';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    /**
     * @param  bool  $suspended  the institution is suspended: nothing can be voted
     * @return 'before'|'open'|'closed'
     */
    public static function of(ElectionStatus $status, DateTimeInterface $startsAt, DateTimeInterface $endsAt, DateTimeInterface $now, bool $suspended = false): string
    {
        if ($suspended) {
            return self::CLOSED;
        }

        if (in_array($status, [ElectionStatus::Closed, ElectionStatus::Published, ElectionStatus::Archived], true)) {
            return self::CLOSED;
        }

        // The end is exclusive: at `ends_at` the election is closed, as `elections:advance` closes it.
        if ($now >= $endsAt) {
            return self::CLOSED;
        }

        // A draft is never shown to a voter; for any caller that asks, it has not begun.
        if ($status === ElectionStatus::Draft || $status === ElectionStatus::Scheduled) {
            return self::BEFORE;
        }

        return $now < $startsAt ? self::BEFORE : self::OPEN;
    }
}
