<?php

namespace App\Enums;

/** Who a ballot is for: every voter of the election, or the voters of the groups it names. */
enum BallotScope: string
{
    case General = 'general';
    case Group = 'group';
}
