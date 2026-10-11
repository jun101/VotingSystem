<?php

namespace App\Support\Credentials;

use App\Support\Exports\FormulaGuard;
use App\Support\Imports\SheetStyles;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * The self-distribution file of a run (FR-CRED-04): one row per voter served, `Nom complet`,
 * `Groupe`, `Code`, `Lien`. The Excel file wears the theme header of the import template; the CSV
 * has a UTF-8 byte order mark and the template's comma and line ends. Every text cell goes through
 * `FormulaGuard`, so a name that could run as a formula is quoted.
 */
final class CodesFileWriter
{
    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $rows  name, group, code, link
     * @return string the bytes of an `.xlsx`
     */
    public function xlsx(array $rows, string $language): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vsc');

        if ($path === false) {
            throw new RuntimeException('No temporary file.');
        }

        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $sheet = $writer->getCurrentSheet();
            $sheet->setName(trans('slips.codes_file.sheet', [], $language));
            foreach ([30, 18, 16, 70] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }
            $sheet->setSheetView(new SheetView(freezeRow: 2));

            $writer->addRow(new Row(
                array_map(static fn (string $title): Cell => new StringCell($title, SheetStyles::header()), $this->headers($language)),
                SheetStyles::HEADER_HEIGHT,
            ));

            foreach ($rows as $index => $row) {
                $style = SheetStyles::body($index);
                $writer->addRow(new Row(
                    array_map(static fn (mixed $value): Cell => $value === '' ? Cell::fromValue(null) : new StringCell(FormulaGuard::guard((string) $value), $style), $row),
                    SheetStyles::ROW_HEIGHT,
                ));
            }

            $writer->close();

            $bytes = file_get_contents($path);

            if ($bytes === false) {
                throw new RuntimeException('The file could not be read back.');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $rows
     * @return string UTF-8 with a byte order mark, separator `,`, lines ending `\r\n`
     */
    public function csv(array $rows, string $language): string
    {
        $handle = fopen('php://memory', 'w+');

        if ($handle === false) {
            throw new RuntimeException('No memory stream.');
        }

        fputcsv($handle, $this->headers($language), ',', '"', '', "\r\n");

        foreach ($rows as $row) {
            fputcsv($handle, array_map(FormulaGuard::guard(...), $row), ',', '"', '', "\r\n");
        }

        rewind($handle);
        $text = stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF".($text === false ? '' : $text);
    }

    /** @return list<string> */
    private function headers(string $language): array
    {
        $headers = trans('slips.codes_file.columns', [], $language);

        return is_array($headers) ? array_map(static fn (mixed $title): string => is_string($title) ? $title : '', array_values($headers)) : [];
    }
}
