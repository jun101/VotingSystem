<?php

namespace App\Actions\Credentials;

use RuntimeException;

/** The file was claimed by another job between the first read and the lock. */
final class FileTaken extends RuntimeException {}
