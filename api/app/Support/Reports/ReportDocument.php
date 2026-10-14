<?php

namespace App\Support\Reports;

use Illuminate\Support\Facades\Lang;
use TCPDF;

/**
 * One report being drawn. Holds the page and the vertical position, so it lives for one document only.
 *
 * @phpstan-import-type Ballot from ResultsReport
 */
final class ReportDocument
{
    private const WIDTH = 612;

    private const HEIGHT = 792;

    private const MARGIN = 54;

    private const FONT = 'helvetica';

    /**
     * Latin letters outside Windows-1252 (Latin Extended-A and a few more: Turkish, Polish, Czech, Romanian,
     * Vietnamese-adjacent) and the plain letter each one is drawn as.
     */
    private const LATIN = [
        'Ā' => 'A', 'ā' => 'a', 'Ă' => 'A', 'ă' => 'a', 'Ą' => 'A', 'ą' => 'a', 'Ć' => 'C', 'ć' => 'c', 'Ĉ' => 'C', 'ĉ' => 'c',
        'Ċ' => 'C', 'ċ' => 'c', 'Č' => 'C', 'č' => 'c', 'Ď' => 'D', 'ď' => 'd', 'Đ' => 'D', 'đ' => 'd', 'Ē' => 'E', 'ē' => 'e',
        'Ĕ' => 'E', 'ĕ' => 'e', 'Ė' => 'E', 'ė' => 'e', 'Ę' => 'E', 'ę' => 'e', 'Ě' => 'E', 'ě' => 'e', 'Ĝ' => 'G', 'ĝ' => 'g',
        'Ğ' => 'G', 'ğ' => 'g', 'Ġ' => 'G', 'ġ' => 'g', 'Ģ' => 'G', 'ģ' => 'g', 'Ĥ' => 'H', 'ĥ' => 'h', 'Ħ' => 'H', 'ħ' => 'h',
        'Ĩ' => 'I', 'ĩ' => 'i', 'Ī' => 'I', 'ī' => 'i', 'Ĭ' => 'I', 'ĭ' => 'i', 'Į' => 'I', 'į' => 'i', 'İ' => 'I', 'ı' => 'i',
        'Ĵ' => 'J', 'ĵ' => 'j', 'Ķ' => 'K', 'ķ' => 'k', 'Ĺ' => 'L', 'ĺ' => 'l', 'Ļ' => 'L', 'ļ' => 'l', 'Ľ' => 'L', 'ľ' => 'l',
        'Ł' => 'L', 'ł' => 'l', 'Ń' => 'N', 'ń' => 'n', 'Ņ' => 'N', 'ņ' => 'n', 'Ň' => 'N', 'ň' => 'n', 'Ō' => 'O', 'ō' => 'o',
        'Ŏ' => 'O', 'ŏ' => 'o', 'Ő' => 'O', 'ő' => 'o', 'Ŕ' => 'R', 'ŕ' => 'r', 'Ŗ' => 'R', 'ŗ' => 'r', 'Ř' => 'R', 'ř' => 'r',
        'Ś' => 'S', 'ś' => 's', 'Ŝ' => 'S', 'ŝ' => 's', 'Ş' => 'S', 'ş' => 's', 'Ţ' => 'T', 'ţ' => 't', 'Ť' => 'T', 'ť' => 't',
        'Ũ' => 'U', 'ũ' => 'u', 'Ū' => 'U', 'ū' => 'u', 'Ŭ' => 'U', 'ŭ' => 'u', 'Ů' => 'U', 'ů' => 'u', 'Ű' => 'U', 'ű' => 'u',
        'Ų' => 'U', 'ų' => 'u', 'Ŵ' => 'W', 'ŵ' => 'w', 'Ŷ' => 'Y', 'ŷ' => 'y', 'Ź' => 'Z', 'ź' => 'z', 'Ż' => 'Z', 'ż' => 'z',
        'Ș' => 'S', 'ș' => 's', 'Ț' => 'T', 'ț' => 't', 'Ə' => 'E', 'ə' => 'e', 'Ơ' => 'O', 'ơ' => 'o', 'Ư' => 'U', 'ư' => 'u',
        'ẞ' => 'SS', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '‐' => '-', '‑' => '-', '′' => "'", '✓' => 'v', '✔' => 'v',
    ];

    private TCPDF $pdf;

    private float $y = self::MARGIN;

    private readonly string $locale;

    public function __construct(private readonly ResultsReport $report)
    {
        $this->locale = $report->language === 'en' ? 'en' : 'fr';
    }

    public function render(): string
    {
        $pdf = new TCPDF('P', 'pt', [self::WIDTH, self::HEIGHT], true, 'UTF-8', false);
        // Text only, so small: left uncompressed, any reader (and the tests) gets the text without inflating.
        $pdf->SetCompression(false);
        $pdf->SetTitle('Report');
        $pdf->SetAuthor('');
        $pdf->SetCreator('');
        $pdf->SetSubject('');
        $pdf->SetKeywords('');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetCellPadding(0);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(120, 120, 120);
        $pdf->SetLineWidth(0.5);
        $pdf->AddPage();
        $this->pdf = $pdf;

        $this->head();

        foreach ($this->report->ballots as $ballot) {
            $this->ballot($ballot);
        }

        return $pdf->Output('', 'S');
    }

    private function head(): void
    {
        $r = $this->report;
        // The date format is a message of the language (lang/*/report.php, `date_format`).
        $format = $this->t('date_format');
        $from = $r->startsAt->copy()->setTimezone($r->timezone)->format($format);
        $to = $r->endsAt->copy()->setTimezone($r->timezone)->format($format);

        $this->wrapped($this->t('heading'), 9, '', 1);
        $this->wrapped($r->institution, 12, '', 4);
        $this->wrapped($r->title, 20, 'B', 4);
        $this->wrapped($this->t('period', ['from' => $from, 'to' => $to, 'zone' => $r->timezone]), 9, '', 8);
        $this->wrapped($this->t('turnout', ['voted' => $r->voted, 'eligible' => $r->eligible, 'percent' => $this->percent($r->percentage)]), 11, 'B', 6);
        $this->rule();
    }

    /** @param  Ballot  $ballot */
    private function ballot(array $ballot): void
    {
        $r = $this->report;
        $this->ensure(90);
        $this->y += 8;

        $this->wrapped($ballot['title'], 14, 'B', 2);
        $this->wrapped($this->t('seats', ['count' => $ballot['seats']]), 9, '', 1);

        if ($ballot['groups'] !== []) {
            $names = implode(', ', array_map(static fn (array $group): string => $group['name'], $ballot['groups']));
            $this->wrapped($this->t('groups', ['names' => $names]), 9, '', 1);
        }

        $this->y += 4;

        // The candidates: every one with the counts, or only the winners and the tied ones.
        $tied = $ballot['tie']['candidates'] ?? [];
        $rows = [];

        foreach ($ballot['candidates'] as $candidate) {
            $isWinner = in_array($candidate['id'], $ballot['winners'], true);
            $isTied = in_array($candidate['id'], $tied, true);

            if (! $r->full && ! $isWinner && ! $isTied) {
                continue;
            }

            $rows[] = [$candidate, $isWinner ? $this->t('winner') : ($isTied ? $this->t('tied') : '')];
        }

        if ($rows === []) {
            $this->wrapped($this->t('no_candidate'), 10, '', 4);
        } else {
            $this->tableHead();

            foreach ($rows as [$candidate, $status]) {
                // After a page break inside a ballot the table's header is drawn again.
                if ($this->ensure(18)) {
                    $this->tableHead();
                }

                $name = trim($candidate['first_name'].' '.$candidate['last_name']);
                $this->cell($name, 10, 'B', $this->column('name'), $this->width('name'));
                $this->cell($candidate['party'] ?? '', 9, '', $this->column('party'), $this->width('party'));

                if ($r->full) {
                    $this->cell((string) $candidate['votes'], 10, '', $this->column('votes'), 40);
                    $this->cell($this->percent($candidate['percentage']), 10, '', $this->column('percent'), 60);
                }

                $this->cell($status, 10, 'B', $this->column('result'), 70);
                $this->y += 17;
            }
        }

        $this->y += 4;

        if ($ballot['winners'] === [] && $ballot['tie'] === null) {
            $this->wrapped($this->t('no_winner'), 10, '', 2);
        }

        if ($ballot['tie'] !== null) {
            $names = [];

            foreach ($ballot['candidates'] as $candidate) {
                if (in_array($candidate['id'], $ballot['tie']['candidates'], true)) {
                    $names[] = trim($candidate['first_name'].' '.$candidate['last_name']);
                }
            }

            $this->wrapped($this->t('tie', ['count' => $ballot['tie']['seats_left'], 'names' => implode(', ', $names)]), 10, 'B', 2);
        }

        if ($r->full) {
            $this->wrapped($this->t('cast', ['count' => $ballot['cast']]), 10, '', 1);
            $this->wrapped($this->t('blank', ['count' => $ballot['blank']]), 10, '', 1);
        }

        if ($r->showCheck) {
            $check = $ballot['check'];
            $this->wrapped($this->t('check', [
                'recorded' => $check['recorded'],
                'expected' => $check['expected'],
                'state' => $this->t($check['ok'] ? 'check_ok' : 'check_failed'),
            ]), 9, '', 1);
        }

        if ($ballot['note'] !== null && trim($ballot['note']) !== '') {
            $this->y += 3;
            $this->wrapped($this->t('note'), 9, 'B', 1);

            foreach (preg_split('/\r\n|\r|\n/', $ballot['note']) ?: [] as $line) {
                $this->wrapped($line, 10, '', 1);
            }
        }

        $this->y += 6;
        $this->rule();
    }

    private function tableHead(): void
    {
        $this->ensure(30);
        $this->cell($this->t('candidate'), 8, '', $this->column('name'), $this->width('name'));
        $this->cell($this->t('party'), 8, '', $this->column('party'), $this->width('party'));

        if ($this->report->full) {
            $this->cell($this->t('votes'), 8, '', $this->column('votes'), 40);
            $this->cell($this->t('percent'), 8, '', $this->column('percent'), 60);
        }

        $this->cell($this->t('result'), 8, '', $this->column('result'), 70);
        $this->y += 12;
    }

    /** The x of a column; the winners-only table gives more room to the names. */
    private function column(string $name): float
    {
        return match ($name) {
            'name' => self::MARGIN,
            'party' => $this->report->full ? 250 : 300,
            'votes' => 390,
            'percent' => 440,
            default => $this->report->full ? 500 : 470,
        };
    }

    private function width(string $name): float
    {
        return $name === 'name'
            ? $this->column('party') - self::MARGIN - 8
            : ($this->report->full ? 132 : 160) - 8;
    }

    /** One string at a column, cut with an ellipsis when it does not fit. */
    private function cell(string $text, float $size, string $style, float $x, float $space): void
    {
        $text = $this->clean($text);

        if ($text === '') {
            return;
        }

        $this->pdf->SetFont(self::FONT, $style, $size);

        while (mb_strlen($text, 'UTF-8') > 4 && $this->measure($text) > $space) {
            $text = rtrim(mb_substr($text, 0, mb_strlen($text, 'UTF-8') - 4, 'UTF-8')).'...';
        }

        $this->pdf->Text($x, $this->y, $text);
    }

    /** A text over the full width, broken into lines at the spaces (and inside a word too long for a line). */
    private function wrapped(string $text, float $size, string $style, float $after): void
    {
        $this->pdf->SetFont(self::FONT, $style, $size);
        $space = self::WIDTH - 2 * self::MARGIN;
        $line = $size + 4;

        foreach ($this->lines($this->clean($text), $space) as $row) {
            $this->ensure($line);
            $this->pdf->SetFont(self::FONT, $style, $size);
            $this->pdf->Text(self::MARGIN, $this->y, $row);
            $this->y += $line;
        }

        $this->y += $after;
    }

    /**
     * @return list<string>
     */
    private function lines(string $text, float $space): array
    {
        if ($text === '') {
            return [''];
        }

        $lines = [];
        $current = '';

        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            // A word wider than a line is cut by characters.
            while ($this->measure($word) > $space && mb_strlen($word, 'UTF-8') > 1) {
                // The longest prefix that fits (at least one character), by binary search: the width only
                // grows with the length, so the loop makes about log2(length) measures, not one per character.
                $low = 1;
                $high = mb_strlen($word, 'UTF-8');

                while ($low < $high) {
                    $mid = intdiv($low + $high + 1, 2);

                    if ($this->measure(mb_substr($word, 0, $mid, 'UTF-8')) > $space) {
                        $high = $mid - 1;
                    } else {
                        $low = $mid;
                    }
                }

                $cut = $low;

                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }

                $lines[] = mb_substr($word, 0, $cut, 'UTF-8');
                $word = mb_substr($word, $cut, null, 'UTF-8');
            }

            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($current !== '' && $this->measure($candidate) > $space) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        $lines[] = $current;

        return $lines;
    }

    private function rule(): void
    {
        $this->ensure(8);
        $this->pdf->Line(self::MARGIN, $this->y, self::WIDTH - self::MARGIN, $this->y);
        $this->y += 8;
    }

    /** A new page when `$height` more points do not fit; true when one was added. */
    private function ensure(float $height): bool
    {
        if ($this->y + $height > self::HEIGHT - self::MARGIN) {
            $this->pdf->AddPage();
            $this->y = self::MARGIN;

            return true;
        }

        return false;
    }

    private function measure(string $text): float
    {
        $width = $this->pdf->GetStringWidth($text);

        return is_numeric($width) ? (float) $width : 0.0;
    }

    /**
     * Control characters out; the text is otherwise as written, except that a character the core font cannot
     * draw (Windows-1252 only) is replaced by its plain Latin letter when `LATIN` knows it, and by `?` when not.
     * The bundled Unicode font of TCPDF is not installed (TCPDF 7 ships none), so this is the documented
     * fallback; the names stay readable, and the text of the PDF stays extractable.
     */
    private function clean(string $text): string
    {
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
        $out = '';

        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            $out .= $this->drawable($char) ? $char : (self::LATIN[$char] ?? '?');
        }

        return $out;
    }

    private function drawable(string $char): bool
    {
        if (strlen($char) === 1) {
            return true;
        }

        return mb_convert_encoding($char, 'Windows-1252', 'UTF-8') !== '?';
    }

    private function percent(float $value): string
    {
        return number_format($value, 1, $this->t('decimal'), '').$this->t('percent_suffix');
    }

    /** @param  array<string, string|int|float>  $replace */
    private function t(string $key, array $replace = []): string
    {
        $line = Lang::get('report.'.$key, $replace, $this->locale);

        return is_string($line) ? $line : $key;
    }
}
