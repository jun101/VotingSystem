<?php

namespace App\Support\Credentials;

/** One slip: what is printed for one voter. The code is in clear here, in memory only. */
final readonly class Slip
{
    public function __construct(
        public string $name,
        public string $group,
        public string $code,
    ) {}
}
