<?php

namespace App\Support\Imports;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
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
            $writer->getCurrentSheet()->setName(trans('imports.template.sheet', [], $language));

            $writer->addRow($this->row(self::HEADER));

            foreach (self::EXAMPLES as $example) {
                $writer->addRow($this->row($example));
            }

            $writer->addNewSheetAndMakeItCurrent()->setName(trans('imports.template.instructions_sheet', [], $language));
            $writer->addRow($this->row([trans('imports.template.column', [], $language), trans('imports.template.description', [], $language)]));

            foreach (self::HEADER as $column) {
                $writer->addRow($this->row([$column, trans('imports.template.columns.'.$column, [], $language)]));
            }

            $writer->addRow($this->row(['', trans('imports.template.notes', [], $language)]));
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
    private function row(array $values): Row
    {
        return new Row(array_map(
            static fn (string $value): Cell => $value === '' ? Cell::fromValue(null) : new StringCell($value, null),
            $values,
        ));
    }
}
