<?php

namespace App\Enums;

/** Chooses the default avatar of a candidate who has no photo. */
enum Sex: string
{
    case Male = 'male';
    case Female = 'female';
}
