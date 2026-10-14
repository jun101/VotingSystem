<?php

namespace App\Support\Imports;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The temporary folder of the check of one import, under the private disk
 * (`imports-tmp/<import uuid>`). The spreadsheet reader writes its working files there; the
 * folder is removed when the check ends, when its job fails, and by `imports:purge` (a worker
 * that was killed leaves it behind).
 */
final class ImportTempFolder
{
    private const BASE = 'imports-tmp';

    /** The folder that holds the folders of every import. */
    public static function base(): string
    {
        return Storage::disk('local')->path(self::BASE);
    }

    /** The folder of one import; the UUID is checked so that it can never be a path. */
    public static function path(string $uuid): string
    {
        if (! Str::isUuid($uuid)) {
            throw new \InvalidArgumentException('Not a UUID.');
        }

        return self::base().'/'.strtolower($uuid);
    }

    /** Creates the folder (private to the user running the worker) and gives its path. */
    public static function make(string $uuid): string
    {
        $path = self::path($uuid);

        if (! is_dir($path)) {
            @mkdir($path, 0700, true);
        }

        return $path;
    }

    public static function remove(string $uuid): void
    {
        File::deleteDirectory(self::path($uuid));
    }

    /**
     * Removes the folders not touched for `$seconds` seconds (their worker is gone).
     *
     * @return int how many were removed
     */
    public static function removeOlderThan(int $seconds): int
    {
        $removed = 0;

        if (! is_dir(self::base())) {
            return 0;
        }

        $limit = time() - $seconds;

        foreach (File::directories(self::base()) as $directory) {
            if (is_string($directory) && filemtime($directory) < $limit && File::deleteDirectory($directory)) {
                $removed++;
            }
        }

        return $removed;
    }
}
