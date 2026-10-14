<?php

namespace App\Support\Voting;

use RuntimeException;

/** The audit public key is not usable: the vote is refused as a whole, with a 500 and nothing recorded. */
final class AuditKeyUnusable extends RuntimeException {}
