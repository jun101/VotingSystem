<?php

namespace App\Support\Exports;

use App\Support\Imports\SheetStyles;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The audit log export (docs/api/audit/GET-audit-export.md), written straight to the output while the rows are
 * read one by one. Same file shapes as the voters export: Excel with the theme header, CSV with a UTF-8 byte
 * order mark, comma and `\r\n`. Every text cell goes through `FormulaGuard`.
 *
 * Stateless: nothing request-scoped is kept.
 */
final class AuditFileWriter
{
    /** @param  iterable<object>  $rows  `created_at`, `action`, `actor_name`, `election_title`, `subject_type`, `subject_uuid`, `details`, `ip_address` */
    public function xlsx(iterable $rows, string $language): void
    {
        // The workbook parts are staged on disk in a folder of its own, removed whatever happens.
        $folder = storage_path('app/private/export-'.bin2hex(random_bytes(8)));
        $previous = ignore_user_abort(true);

        try {
            if (! mkdir($folder, 0700, true) && ! is_dir($folder)) {
                throw new \RuntimeException('The export working folder cannot be created.');
            }

            $writer = new Writer(new Options(tempFolder: $folder));
            // Not `openToBrowser`: it sends its own headers, and the response has set them.
            $writer->openToFile('php://output');
            $sheet = $writer->getCurrentSheet();
            $sheet->setName(trans('exports.audit.sheet', [], $language));
            foreach ([20, 26, 24, 30, 36, 50, 18] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }
            $sheet->setSheetView(new SheetView(freezeRow: 2));

            $writer->addRow(new Row(
                array_map(static fn (string $title): Cell => new StringCell($title, SheetStyles::header()), $this->headers($language)),
                SheetStyles::HEADER_HEIGHT,
            ));

            $index = 0;

            foreach ($rows as $row) {
                $style = SheetStyles::body($index++);
                $writer->addRow(new Row(
                    array_map(static fn (string $value): Cell => new StringCell($value, $style), $this->values($row)),
                    SheetStyles::ROW_HEIGHT,
                ));
            }

            $writer->close();
        } finally {
            $this->removeFolder($folder);
            ignore_user_abort((bool) $previous);
        }
    }

    /**
     * @param  iterable<object>  $rows
     * @param  resource  $handle  an open stream the CSV is written to
     */
    public function csv(iterable $rows, string $language, $handle): void
    {
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $this->headers($language), ',', '"', '', "\r\n");

        foreach ($rows as $row) {
            fputcsv($handle, $this->values($row), ',', '"', '', "\r\n");
        }
    }

    /** @return list<string> */
    private function headers(string $language): array
    {
        $columns = trans('exports.audit.columns', [], $language);

        return is_array($columns) ? array_values(array_map(static fn (mixed $title): string => is_string($title) ? $title : '', $columns)) : [];
    }

    /**
     * The cells of a row, each text guarded against formulas.
     *
     * @return list<string>
     */
    private function values(object $row): array
    {
        $text = static fn (mixed $value): string => is_string($value) ? $value : '';

        $details = is_string($row->details ?? null) ? json_decode($row->details, true) : null;
        $title = $text($row->election_title ?? null);

        if ($title === '' && is_array($details) && is_string($details['title'] ?? null)) {
            // The election was deleted: its title was kept in the details.
            $title = $details['title'];
        }

        $type = $text($row->subject_type ?? null);
        $subject = $type === '' ? '' : $type.' '.$text($row->subject_uuid ?? null);
        $json = is_array($details) && $details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';

        return array_map(FormulaGuard::guard(...), [
            $text($row->created_at ?? null),
            $text($row->action ?? null),
            $text($row->actor_name ?? null),
            $title,
            trim($subject),
            is_string($json) ? $json : '{}',
            $text($row->ip_address ?? null),
        ]);
    }

    private function removeFolder(string $folder): void
    {
        if (! is_dir($folder)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if (! $item instanceof \SplFileInfo) {
                continue;
            }

            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($folder);
    }
}
