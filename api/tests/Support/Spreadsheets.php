<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Spreadsheets built and read by the tests (slice 08: the voter import), with no spreadsheet library and no
 * ZIP extension, so the tests do not depend on what the code under test uses. An `.xlsx` is a ZIP of XML
 * parts: this writes a small valid one (stored entries, inline strings) and reads any (stored or deflated
 * entries, shared or inline strings).
 *
 * Part of the acceptance harness: it is not edited when a slice is coded.
 */
final class Spreadsheets
{
    public const HEADER = ['nom_complet', 'groupe', 'identifiant', 'courriel', 'telephone'];

    /** A cell whose formula text is `$formula` and whose cached value is `$cached`. */
    public static function formula(string $formula, string $cached): array
    {
        return ['__formula' => $formula, '__cached' => $cached];
    }

    /**
     * @param  list<list<string|int|float|null|array<string, string>>>  $rows
     * @return string the bytes of an `.xlsx`
     */
    public static function xlsx(array $rows, string $sheet = 'Feuille1'): string
    {
        $sheetData = '';
        foreach ($rows as $r => $row) {
            $sheetData .= '<row r="'.($r + 1).'">';
            foreach (array_values($row) as $c => $cell) {
                $ref = self::column($c).($r + 1);
                if ($cell === null || $cell === '') {
                    continue;
                }
                if (is_array($cell)) {
                    $sheetData .= '<c r="'.$ref.'" t="str"><f>'.htmlspecialchars($cell['__formula'], ENT_XML1).'</f><v>'
                        .htmlspecialchars($cell['__cached'], ENT_XML1).'</v></c>';
                } elseif (is_int($cell) || is_float($cell)) {
                    $sheetData .= '<c r="'.$ref.'"><v>'.$cell.'</v></c>';
                } else {
                    $sheetData .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'
                        .htmlspecialchars((string) $cell, ENT_XML1).'</t></is></c>';
                }
            }
            $sheetData .= '</row>';
        }

        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.htmlspecialchars($sheet, ENT_XML1).'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetData.'</sheetData></worksheet>',
        ]);
    }

    /**
     * @param  list<list<string|null>>  $rows
     */
    public static function csv(array $rows, string $separator = ',', bool $bom = false, string $encoding = 'UTF-8'): string
    {
        $handle = fopen('php://memory', 'w+');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($cell) => (string) $cell, $row), $separator, '"', '\\');
        }
        rewind($handle);
        $text = stream_get_contents($handle);
        fclose($handle);

        if ($encoding !== 'UTF-8') {
            $text = mb_convert_encoding($text, $encoding, 'UTF-8');
        }

        return ($bom ? "\xEF\xBB\xBF" : '').$text;
    }

    /** The bytes of a header and `$count` plain data rows, as an `.xlsx`. */
    public static function manyRows(int $count): string
    {
        $rows = [self::HEADER];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ["Électeur {$i}", 'Groupe '.($i % 5), "ID-{$i}", "e{$i}@example.ht", null];
        }

        return self::xlsx($rows);
    }

    /** The first sheet of an `.xlsx` as a list of rows of strings (a missing cell is `''`). */
    public static function read(string $bytes): array
    {
        $parts = self::unzip($bytes);
        $shared = [];

        if (isset($parts['xl/sharedStrings.xml'])) {
            $xml = simplexml_load_string($parts['xl/sharedStrings.xml']);
            foreach ($xml->si as $si) {
                $text = '';
                foreach ($si->xpath('.//*[local-name()="t"]') as $t) {
                    $text .= (string) $t;
                }
                $shared[] = $text;
            }
        }

        $sheet = $parts['xl/worksheets/sheet1.xml'] ?? null;
        expect($sheet)->not->toBeNull('the workbook has no first sheet');

        $xml = simplexml_load_string($sheet);
        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                preg_match('/^([A-Z]+)/', (string) $c['r'], $m);
                $index = self::columnIndex($m[1]);
                $type = (string) $c['t'];
                if ($type === 's') {
                    $value = $shared[(int) $c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = '';
                    foreach ($c->is->xpath('.//*[local-name()="t"]') as $t) {
                        $value .= (string) $t;
                    }
                } else {
                    $value = (string) $c->v;
                }
                $cells[$index] = $value;
            }
            $line = [];
            for ($i = 0; $i <= ($cells === [] ? -1 : max(array_keys($cells))); $i++) {
                $line[] = $cells[$i] ?? '';
            }
            $rows[(int) $row['r'] - 1] = $line;
        }
        ksort($rows);

        return array_values($rows);
    }


    /**
     * A workbook whose `xl/sharedStrings.xml` says it is 100 bytes long and inflates to `$megabytes` MB of text
     * (a zip bomb with a false declared size). Written in chunks, so the test itself stays small.
     */
    public static function bombXlsx(int $megabytes): string
    {
        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/></Types>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Feuille1" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row></sheetData></worksheet>',
        ];

        $local = '';
        $central = '';
        $count = 0;
        $entry = function (string $name, string $stored, int $crc, int $declared, int $method) use (&$local, &$central, &$count): void {
            $offset = strlen($local);
            $local .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, 0, 0x21, $crc, strlen($stored), $declared, strlen($name), 0).$name.$stored;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, 0, 0x21, $crc, strlen($stored), $declared, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
            $count++;
        };

        foreach ($parts as $name => $data) {
            $entry($name, $data, crc32($data), strlen($data), 0);
        }

        $deflate = deflate_init(ZLIB_ENCODING_RAW);
        $hash = hash_init('crc32b');
        $chunk = str_repeat('a', 1024 * 1024);
        $compressed = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" uniqueCount="1"><si><t>';
        $stream = deflate_add($deflate, $compressed, ZLIB_NO_FLUSH);
        hash_update($hash, $compressed);
        for ($i = 0; $i < $megabytes; $i++) {
            $stream .= deflate_add($deflate, $chunk, ZLIB_NO_FLUSH);
            hash_update($hash, $chunk);
        }
        $tail = '</t></si></sst>';
        $stream .= deflate_add($deflate, $tail, ZLIB_FINISH);
        hash_update($hash, $tail);
        $entry('xl/sharedStrings.xml', $stream, (int) hexdec(hash_final($hash)), 100, 8);

        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($local), 0);

        return $local.$central.$end;
    }

    /**
     * A workbook with a hostile sheet XML: `$sheet` replaces the `<sheetData>` document, `$prolog` is put before the
     * root (a DOCTYPE with entities).
     */
    public static function xlsxWithSheetXml(string $sheetData, string $prolog = '', string $dimension = '', string $sheetName = 'Feuille1', bool $withInstructions = false): string
    {
        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/></Types>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.htmlspecialchars($sheetName, ENT_XML1).'" sheetId="1" r:id="rId1"/>'.($withInstructions ? '<sheet name="Instructions" sheetId="2" r:id="rId2"/>' : '').'</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'.($withInstructions ? '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>' : '').'</Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$prolog.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$dimension.'<sheetData>'.$sheetData.'</sheetData></worksheet>',
        ] + ($withInstructions ? ['xl/worksheets/sheet2.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Colonne</t></is></c></row></sheetData></worksheet>'] : []));
    }

    /** The bytes of a file response, whatever kind of response it is. */
    public static function body(\Illuminate\Testing\TestResponse $response): string
    {
        $base = $response->baseResponse;

        if ($base instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            return (string) file_get_contents($base->getFile()->getPathname());
        }

        if ($base instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            return $response->streamedContent();
        }

        return (string) $response->getContent();
    }

    /** Wraps bytes in an upload as a browser sends a chosen file. */
    public static function upload(string $bytes, string $name = 'electeurs.xlsx', ?string $mime = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vs8');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $mime ?? 'application/octet-stream', null, true);
    }

    /** Empties the private disk of the uploaded files. */
    public static function clearImports(): void
    {
        $disk = Storage::disk('imports');
        foreach ($disk->allFiles() as $file) {
            $disk->delete($file);
        }
    }

    /** The files on the private disk of the uploaded files, sorted. */
    public static function importFiles(): array
    {
        $files = Storage::disk('imports')->allFiles();
        sort($files);

        return $files;
    }

    private static function column(int $index): string
    {
        $name = '';
        $n = $index + 1;
        while ($n > 0) {
            $n--;
            $name = chr(65 + $n % 26).$name;
            $n = intdiv($n, 26);
        }

        return $name;
    }

    private static function columnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + ord($ch) - 64;
        }

        return $n - 1;
    }

    /**
     * A ZIP of stored entries (also used for a ZIP that is not a workbook).
     *
     * @param  array<string, string>  $entries  name => contents
     */
    public static function zip(array $entries): string
    {
        $local = '';
        $central = '';
        $count = 0;
        foreach ($entries as $name => $data) {
            $crc = crc32($data);
            $size = strlen($data);
            $offset = strlen($local);
            $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0x21, $crc, $size, $size, strlen($name), 0).$name;
            $local .= $header.$data;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0x21, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
            $count++;
        }
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($local), 0);

        return $local.$central.$end;
    }

    /** @return array<string, string> name => contents */
    private static function unzip(string $bytes): array
    {
        $eocd = strrpos($bytes, "PK\x05\x06");
        expect($eocd)->not->toBeFalse('not a ZIP');

        $end = unpack('vdisk/vcdDisk/ventriesDisk/ventries/Vsize/Voffset', substr($bytes, $eocd + 4, 16));
        $position = $end['offset'];
        $parts = [];

        for ($i = 0; $i < $end['entries']; $i++) {
            $c = unpack('Vsig/vmadeBy/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnameLen/vextraLen/vcommentLen/vdisk/vinternal/Vexternal/Voffset', substr($bytes, $position, 46));
            $name = substr($bytes, $position + 46, $c['nameLen']);
            $position += 46 + $c['nameLen'] + $c['extraLen'] + $c['commentLen'];

            $l = unpack('vnameLen/vextraLen', substr($bytes, $c['offset'] + 26, 4));
            $data = substr($bytes, $c['offset'] + 30 + $l['nameLen'] + $l['extraLen'], $c['csize']);
            $parts[$name] = $c['method'] === 8 ? gzinflate($data) : $data;
        }

        return $parts;
    }
}
