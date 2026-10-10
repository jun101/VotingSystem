<?php

namespace App\Enums;

/** The verdict on one checked row of a voter file (`voter_import_rows.status`). */
enum ImportRowStatus: string
{
    case Ok = 'ok';
    case Invalid = 'invalid';
    /** A row with at least one duplicate reason; it may hold other reasons too. */
    case Duplicate = 'duplicate';
}
