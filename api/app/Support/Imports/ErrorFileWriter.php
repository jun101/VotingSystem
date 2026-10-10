<?php

namespace App\Support\Imports;

use App\Models\VoterImport;
use App\Models\VoterImportRow;
use App\Support\Exports\FormulaGuard;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * The file of rejected rows of an import: the template's columns, then `ligne` and `raison`.
 * The values are the cells as the user wrote them. Every text is written as text, and one that a
 * spreadsheet could take for a formula gets a single quote in front (NFR-SEC-06).
 */
final class ErrorFileWriter
{
    public const HEADER = [...VoterFileTemplate::HEADER, 'ligne', 'raison'];

    /** The text as it is written in a cell: prefixed with `'` when it could run as a formula. */
    public static function guard(string $text): string
    {
        return FormulaGuard::guard($text);
    }

    /**
     * @param  iterable<VoterImportRow>  $rows  the rejected rows, in file order
     * @return string the bytes of the `.xlsx`
     */
    public function xlsx(iterable $rows, string $language): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vse');

        if ($path === false) {
            throw new RuntimeException('No temporary file.');
        }

        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName(trans('imports.template.sheet', [], $language));
            $writer->addRow(new Row(array_map(static fn (string $name): Cell => new StringCell($name, null), self::HEADER)));

            foreach ($rows as $row) {
                $raw = is_array($row->raw) ? $row->raw : [];
                $cells = [];

                foreach (VoterImport::FIELDS as $field) {
                    $value = $raw[$field] ?? null;
                    $cells[] = is_string($value) && $value !== ''
                        ? new StringCell(FormulaGuard::guard($value), null)
                        : Cell::fromValue(null);
                }

                $cells[] = Cell::fromValue($row->line);
                $cells[] = new StringCell(ReasonWords::phrase($row->reasons, $language), null);

                $writer->addRow(new Row($cells));
            }

            $writer->close();

            $bytes = file_get_contents($path);

            if ($bytes === false) {
                throw new RuntimeException('The error file could not be read back.');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }
}
