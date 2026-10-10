<?php

namespace App\Support\Imports;

use App\Models\VoterImport;
use App\Models\VoterImportRow;
use App\Support\Exports\FormulaGuard;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Entity\SheetView;
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
            $sheet = $writer->getCurrentSheet();
            $sheet->setName(trans('imports.template.sheet', [], $language));
            foreach ([30, 18, 16, 32, 20, 9, 60] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }
            $sheet->setSheetView(new SheetView(freezeRow: 2));

            $writer->addRow(new Row(array_map(
                static fn (string $name, int $index): Cell => new StringCell($name, $index < count(VoterFileTemplate::HEADER) ? SheetStyles::header() : SheetStyles::addedHeader()),
                self::HEADER,
                array_keys(self::HEADER),
            ), SheetStyles::HEADER_HEIGHT));

            $line = 0;

            foreach ($rows as $row) {
                $raw = is_array($row->raw) ? $row->raw : [];
                $cells = [];

                foreach (VoterImport::FIELDS as $field) {
                    $value = $raw[$field] ?? null;
                    $cells[] = is_string($value) && $value !== ''
                        ? new StringCell(FormulaGuard::guard($value), SheetStyles::body($line))
                        : new StringCell('', SheetStyles::body($line));
                }

                $cells[] = new NumericCell($row->line, SheetStyles::body($line));
                $cells[] = new StringCell(ReasonWords::phrase($row->reasons, $language), SheetStyles::reason());

                $writer->addRow(new Row($cells, SheetStyles::ROW_HEIGHT));
                $line++;
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
