<?php

namespace App\Enums;

/** Who a ballot is for: every voter of the election. Slice 09 adds the group scope. */
enum BallotScope: string
{
    case General = 'general';
}
