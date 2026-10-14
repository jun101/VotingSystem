<?php

namespace App\Support\Imports;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * The template file of the voter import, built on the fly: the header row and three examples.
 * No cell is a formula: every value is written as text (a phone starting with `+` stays text).
 */
final class VoterFileTemplate
{
    public const HEADER = ['nom_complet', 'groupe', 'identifiant', 'courriel', 'telephone'];

    private const EXAMPLES = [
        ['Rose-Marie Désir', '4e année', 'E-2041', 'rm.desir@example.ht', '+509 3712 4455'],
        ['Jean-Baptiste Pierre', '4e année', 'E-2042', 'jb.pierre@example.ht', '+509 3712 4456'],
        ['Nadège Louis', '5e année', 'E-2107', 'nadege.louis@example.ht', '+509 3712 4457'],
    ];

    /**
     * The bytes of an `.xlsx`: the voters sheet, then an `Instructions` sheet in the given language.
     */
    public function xlsx(string $language): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vst');

        if ($path === false) {
            throw new RuntimeException('No temporary file.');
        }

        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $sheet = $writer->getCurrentSheet();
            $sheet->setName(trans('imports.template.sheet', [], $language));
            foreach ([30, 18, 16, 32, 20] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }
            // The header row stays in view while a long list is filled in.
            $sheet->setSheetView(new SheetView(freezeRow: 2));

            $writer->addRow($this->row(self::HEADER, SheetStyles::header(), SheetStyles::HEADER_HEIGHT));

            foreach (self::EXAMPLES as $index => $example) {
                $writer->addRow($this->row($example, SheetStyles::body($index), SheetStyles::ROW_HEIGHT));
            }

            $instructions = $writer->addNewSheetAndMakeItCurrent();
            $instructions->setName(trans('imports.template.instructions_sheet', [], $language));
            $instructions->setColumnWidth(20, 1);
            $instructions->setColumnWidth(90, 2);

            $writer->addRow(new Row([
                new StringCell(trans('imports.template.column', [], $language), SheetStyles::header()),
                new StringCell(trans('imports.template.description', [], $language), SheetStyles::header()),
            ], SheetStyles::HEADER_HEIGHT));

            foreach (self::HEADER as $index => $column) {
                $writer->addRow(new Row([
                    new StringCell($column, SheetStyles::columnName()),
                    new StringCell(trans('imports.template.columns.'.$column, [], $language), SheetStyles::body($index)->withShouldWrapText(true)),
                ], SheetStyles::ROW_HEIGHT));
            }

            $writer->addRow(new Row([
                Cell::fromValue(null),
                new StringCell(trans('imports.template.notes', [], $language), SheetStyles::body(1)->withShouldWrapText(true)->withFontItalic(true)),
            ], 36.0));
            $writer->close();

            $bytes = file_get_contents($path);

            if ($bytes === false) {
                throw new RuntimeException('The template could not be read back.');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }

    /** The bytes of a `.csv`: UTF-8 with a byte-order mark, separator `,`. */
    public function csv(): string
    {
        $handle = fopen('php://memory', 'w+');

        if ($handle === false) {
            throw new RuntimeException('No memory stream.');
        }

        foreach ([self::HEADER, ...self::EXAMPLES] as $row) {
            fputcsv($handle, $row, ',', '"', '', "\r\n");
        }

        rewind($handle);
        $text = stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF".($text === false ? '' : $text);
    }

    /**
     * @param  list<string>  $values
     */
    private function row(array $values, ?Style $style = null, float $height = Row::DEFAULT_HEIGHT): Row
    {
        return new Row(array_map(
            static fn (string $value): Cell => $value === '' ? Cell::fromValue(null) : new StringCell($value, $style),
            $values,
        ), $height);
    }
}
