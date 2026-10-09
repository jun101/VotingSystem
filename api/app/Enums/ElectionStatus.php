<?php

namespace App\Enums;

/** Where an election is in its life (docs/design/database.md section 2.2). Stored as text. */
enum ElectionStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Closed = 'closed';
    case Published = 'published';
    case Archived = 'archived';

    /** The order of the list: open first, archived last. */
    public function listRank(): int
    {
        return match ($this) {
            self::Open => 0,
            self::Scheduled => 1,
            self::Draft => 2,
            self::Closed => 3,
            self::Published => 4,
            self::Archived => 5,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
