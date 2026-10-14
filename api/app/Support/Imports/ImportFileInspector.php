<?php

namespace App\Support\Imports;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Judges an uploaded voter file by its content, never by its name or its declared type
 * (docs/api/voter-imports/POST-elections-{election}-voter-imports.md): an `.xlsx` is a ZIP that
 * holds `[Content_Types].xml` and `xl/workbook.xml`; a `.csv` is text with no NUL byte.
 */
final class ImportFileInspector
{
    public const MAX_BYTES = 5242880;

    /** What the entries of a ZIP may add up to once expanded (a zip bomb stays under this). */
    public const MAX_EXPANDED_BYTES = 104857600;

    public const XLSX = 'xlsx';

    public const CSV = 'csv';

    /**
     * @return 'xlsx'|'csv'
     *
     * @throws ValidationException `file`: `empty`, `too_large` or `invalid_type`
     */
    public function kind(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $size = $file->getSize();

        if ($path === '' || $size === false) {
            throw $this->refuse('invalid_type');
        }

        if ($size === 0) {
            throw $this->refuse('empty');
        }

        if ($size > self::MAX_BYTES) {
            throw $this->refuse('too_large');
        }

        if (self::looksLikeZip($path)) {
            return $this->workbook($path);
        }

        $bytes = file_get_contents($path);

        if ($bytes === false || str_contains($bytes, "\0")) {
            throw $this->refuse('invalid_type');
        }

        return self::CSV;
    }

    /** Whether a stored file starts as a ZIP does (the check of the content has been made at upload). */
    public static function looksLikeZip(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = fread($handle, 4);
        fclose($handle);

        return $head === "PK\x03\x04";
    }

    /** @return 'xlsx' */
    private function workbook(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw $this->refuse('invalid_type');
        }

        try {
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                throw $this->refuse('invalid_type');
            }

            // The sizes the ZIP declares are not trusted: the bytes are counted as they are inflated.
            if (self::inflatedBytes($zip, self::MAX_EXPANDED_BYTES) > self::MAX_EXPANDED_BYTES) {
                throw $this->refuse('too_large');
            }
        } finally {
            $zip->close();
        }

        return self::XLSX;
    }

    /**
     * The real size of the entries of an open ZIP once inflated, counted by reading each one
     * through a stream. Counting stops as soon as the total passes `$limit` (the result is then
     * above it, not the full size), so a bomb costs about `$limit` bytes of work. An entry that
     * cannot be read to its end counts for what was read.
     */
    public static function inflatedBytes(ZipArchive $zip, int $limit): int
    {
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false || str_ends_with($name, '/')) {
                continue;
            }

            $stream = $zip->getStream($name);

            if ($stream === false) {
                continue;
            }

            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 65536);

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    $total += strlen($chunk);

                    if ($total > $limit) {
                        return $total;
                    }
                }
            } finally {
                fclose($stream);
            }
        }

        return $total;
    }

    private function refuse(string $code): ValidationException
    {
        return ValidationException::withMessages(['file' => [$code]]);
    }
}
