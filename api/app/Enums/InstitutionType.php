<?php

namespace App\Enums;

enum InstitutionType: string
{
    case School = 'school';
    case University = 'university';
    case Association = 'association';
    case Other = 'other';
}
