<?php

namespace App\Support\Credentials;

/**
 * Turns the slips of one file into PDF bytes (US Letter, 10 slips a page). The job holds the
 * bytes in memory, encrypts them and stores them; the writer never touches a disk.
 */
interface SlipWriter
{
    public function write(SlipSheet $sheet): string;
}
