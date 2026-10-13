<?php

namespace App\Support;

/** Which edition this installation is (config/deployment.php). Read from the config each time, never kept. */
final class Deployment
{
    public const ONLINE = 'online';

    public const SCHOOL = 'school';

    public static function mode(): string
    {
        $mode = config('deployment.mode');

        return is_string($mode) ? $mode : self::ONLINE;
    }

    public static function isSchool(): bool
    {
        return self::mode() === self::SCHOOL;
    }
}
