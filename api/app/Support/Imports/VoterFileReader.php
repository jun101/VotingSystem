<?php

namespace App\Support\Imports;

use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\TextRunCell;
use OpenSpout\Common\Helper\EncodingHelper;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Streams the first sheet of a stored voter file, line by line, as text. Values only: a formula
 * is never evaluated (a cached result is used when the file holds one), and nothing is logged.
 */
final class VoterFileReader
{
    /** A header wider than this is refused by the check, and `columns` keeps this many cells at most. */
    public const MAX_COLUMNS = 50;

    /**
     * The lines of the file as `line number => cells`, the header being line 1. Empty lines are
     * kept so that the numbers are the file's own. A cell is its text, or null when it is empty.
     * The header is given whole; every other line is cut to the header's width (at most
     * MAX_COLUMNS cells) before its cells are turned into text, whatever the sheet's declared
     * dimension is. The working files of the reader go to `$tempFolder` when it is given.
     *
     * @return Generator<int, list<string|null>>
     *
     * @throws RuntimeException when the file cannot be read as a spreadsheet
     */
    public function lines(string $path, ?string $tempFolder = null): Generator
    {
        $reader = $this->open($path, $tempFolder);

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                $line = 0;
                $width = null;

                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = [];

                    foreach ($width === null ? $row->cells : array_slice($row->cells, 0, $width) as $cell) {
                        $cells[] = $this->text($cell);
                    }

                    if ($width === null) {
                        $width = max(1, min(self::MAX_COLUMNS, $this->usedWidth($cells)));
                    }

                    yield $line => $cells;
                }

                // Only the first sheet is read.
                break;
            }
        } finally {
            $reader->close();
        }
    }

    /** @param  list<string|null>  $cells */
    private function usedWidth(array $cells): int
    {
        for ($width = count($cells); $width > 0; $width--) {
            $cell = $cells[$width - 1];

            if ($cell !== null && trim($cell) !== '') {
                break;
            }
        }

        return $width;
    }

    private function open(string $path, ?string $tempFolder): XlsxReader|CsvReader
    {
        if (ImportFileInspector::looksLikeZip($path)) {
            return new XlsxReader(new XlsxOptions(tempFolder: $tempFolder === '' ? null : $tempFolder, SHOULD_PRESERVE_EMPTY_ROWS: true));
        }

        $bytes = file_get_contents($path);

        if ($bytes === false) {
            throw new RuntimeException('unreadable');
        }

        return new CsvReader(new CsvOptions(
            SHOULD_PRESERVE_EMPTY_ROWS: true,
            FIELD_DELIMITER: self::delimiterOf($bytes),
            ENCODING: mb_check_encoding($bytes, 'UTF-8') ? EncodingHelper::ENCODING_UTF8 : 'WINDOWS-1252',
        ));
    }

    /** `;` when the first record has more of them than commas (outside quotes), else `,`. */
    public static function delimiterOf(string $bytes): string
    {
        $head = substr($bytes, 0, 65536);
        $commas = 0;
        $semicolons = 0;
        $quoted = false;

        for ($i = 0, $length = strlen($head); $i < $length; $i++) {
            $char = $head[$i];

            if ($char === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted && ($char === "\n" || $char === "\r")) {
                break;
            } elseif (! $quoted && $char === ',') {
                $commas++;
            } elseif (! $quoted && $char === ';') {
                $semicolons++;
            }
        }

        return $semicolons > $commas ? ';' : ',';
    }

    private function text(Cell $cell): ?string
    {
        if ($cell instanceof ErrorCell) {
            return null;
        }

        if ($cell instanceof TextRunCell) {
            $text = '';

            foreach ($cell->getValue() as $run) {
                $text .= $run->text;
            }

            return $text;
        }

        if ($cell instanceof FormulaCell) {
            // Never evaluated: the result the file cached is used. The reader also gives a plain
            // text cell that begins with `=` as a formula cell without a cached result; that text
            // is kept as text (it is never run: the error file prefixes it, NFR-SEC-06).
            $value = $cell->getComputedValue() ?? $cell->getValue();
        } else {
            $value = $cell->getValue();
        }

        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => floor($value) === $value && abs($value) < 1.0E+15 ? (string) (int) $value : (string) $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            default => null,
        };
    }
}
