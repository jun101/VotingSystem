<?php

namespace App\Support\Exports;

use App\Support\Imports\SheetStyles;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The voters export (docs/api/turnout/GET-elections-{election}-voters-export.md), written straight to
 * the output while the rows are read one by one: the file is never held whole in memory. The Excel
 * file wears the theme header of the import template; the CSV has a UTF-8 byte order mark, the
 * template's comma and `\r\n`. Every text cell goes through `FormulaGuard`. No code, no hash, no
 * link, no time of vote: only what the voters list shows, and `Oui`/`Non` for `voted=all`.
 *
 * Stateless: nothing request-scoped is kept.
 */
final class VotersFileWriter
{
    /**
     * @param  iterable<object>  $rows  `full_name`, `group_name`, `identifier`, `email`, `phone`, `has_voted`
     * @param  bool  $withVoted  add the `A voté` column
     */
    public function xlsx(iterable $rows, string $language, bool $withVoted): void
    {
        $writer = new Writer;
        // Not `openToBrowser`: it sends its own headers, and the response has set them.
        $writer->openToFile('php://output');
        $sheet = $writer->getCurrentSheet();
        $sheet->setName(trans('exports.voters.sheet', [], $language));
        foreach ($withVoted ? [30, 18, 16, 32, 20, 12] : [30, 18, 16, 32, 20] as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }
        $sheet->setSheetView(new SheetView(freezeRow: 2));

        $writer->addRow(new Row(
            array_map(static fn (string $title): Cell => new StringCell($title, SheetStyles::header()), $this->headers($language, $withVoted)),
            SheetStyles::HEADER_HEIGHT,
        ));

        $index = 0;

        foreach ($rows as $row) {
            $style = SheetStyles::body($index++);
            $writer->addRow(new Row(
                array_map(static fn (string $value): Cell => new StringCell($value, $style), $this->guarded($row, $language, $withVoted)),
                SheetStyles::ROW_HEIGHT,
            ));
        }

        $writer->close();
    }

    /**
     * @param  iterable<object>  $rows
     * @param  resource  $handle  an open stream the CSV is written to
     */
    public function csv(iterable $rows, string $language, bool $withVoted, $handle): void
    {
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $this->headers($language, $withVoted), ',', '"', '', "\r\n");

        foreach ($rows as $row) {
            fputcsv($handle, $this->guarded($row, $language, $withVoted), ',', '"', '', "\r\n");
        }
    }

    /** @return list<string> */
    private function headers(string $language, bool $withVoted): array
    {
        $columns = trans('exports.voters.columns', [], $language);
        $headers = is_array($columns) ? array_values(array_map(static fn (mixed $title): string => is_string($title) ? $title : '', $columns)) : [];

        if ($withVoted) {
            $headers[] = trans('exports.voters.has_voted', [], $language);
        }

        return $headers;
    }

    /**
     * The cells of a row, each text guarded against formulas (the phone keeps its leading `+`).
     *
     * @return list<string>
     */
    private function guarded(object $row, string $language, bool $withVoted): array
    {
        $values = $this->values($row, $language, $withVoted);

        foreach ($values as $index => $value) {
            $values[$index] = $index === 4 ? FormulaGuard::guardPhone($value) : FormulaGuard::guard($value);
        }

        return $values;
    }

    /** @return list<string> */
    private function values(object $row, string $language, bool $withVoted): array
    {
        $text = static fn (mixed $value): string => is_string($value) ? $value : '';

        $values = [
            $text($row->full_name ?? null),
            $text($row->group_name ?? null),
            $text($row->identifier ?? null),
            $text($row->email ?? null),
            $text($row->phone ?? null),
        ];

        if ($withVoted) {
            $values[] = trans('exports.voters.'.(! empty($row->has_voted) ? 'yes' : 'no'), [], $language);
        }

        return $values;
    }
}
