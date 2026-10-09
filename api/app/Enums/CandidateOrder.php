<?php

namespace App\Enums;

/** How the candidates of a ballot are ordered on the screen of a voter (FR-BAL-04). */
enum CandidateOrder: string
{
    case Manual = 'manual';
    case Shuffled = 'shuffled';
}
