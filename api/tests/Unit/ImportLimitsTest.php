<?php

use App\Actions\Imports\CheckVoterImportFile;
use App\Jobs\CheckVoterImport;
use App\Models\VoterImport;
use App\Support\Exports\FormulaGuard;
use App\Support\Imports\ImportFileInspector;
use App\Support\Imports\ImportTempFolder;
use App\Support\Imports\VoterFileReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Accounts;
use Tests\Support\Spreadsheets;
use Tests\Support\Team;

/** The check of a file written to the imports disk, run as a worker would (nobody signed in). */
function limitsCheck(string $bytes): array
{
    Accounts::reset();
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $uuid = Accounts::plantImport(['election' => $election, 'status' => 'uploaded', 'columns' => null, 'mapping' => null, 'rows' => []]);
    $model = VoterImport::withoutInstitutionScope()->where('uuid', $uuid)->firstOrFail();
    Storage::disk('imports')->put($model->stored_file, $bytes);

    app(CheckVoterImportFile::class)($model);

    return [$uuid, Accounts::importRow($uuid), Accounts::importRows($uuid)];
}

function limitsTemp(): string
{
    $path = tempnam(sys_get_temp_dir(), 'vsl');
    file_put_contents($path, '');

    return $path;
}

it('counts the real inflated bytes of a ZIP, not the sizes it declares [NFR-SEC-06]', function () {
    $path = limitsTemp();
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('a.xml', str_repeat('a', 1000));
    $zip->addFromString('dir/b.xml', str_repeat('b', 2500));
    $zip->addEmptyDir('empty');
    $zip->close();

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::RDONLY);
    expect(ImportFileInspector::inflatedBytes($zip, 1_000_000))->toBe(3500);
    $zip->close();
    unlink($path);
});

it('stops counting just past the limit on a bomb whose declared size is a lie [NFR-SEC-06]', function () {
    $path = limitsTemp();
    file_put_contents($path, Spreadsheets::bombXlsx(6));
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::RDONLY);

    $counted = ImportFileInspector::inflatedBytes($zip, 1_048_576);

    expect($counted)->toBeGreaterThan(1_048_576)->and($counted)->toBeLessThan(1_048_576 + 200_000);
    $zip->close();
    unlink($path);
});

it('refuses a bomb as too_large when the file is judged [NFR-SEC-06]', function () {
    $path = limitsTemp();
    file_put_contents($path, Spreadsheets::bombXlsx(120));
    $file = new UploadedFile($path, 'b.xlsx', null, null, true);

    try {
        (new ImportFileInspector)->kind($file);
        $codes = [];
    } catch (ValidationException $e) {
        $codes = $e->errors()['file'];
    }

    expect($codes)->toBe(['too_large']);
    unlink($path);
});

it('cuts every line after the header to the header width, whatever the sheet declares [NFR-SEC-06]', function () {
    $path = limitsTemp();
    $rows = '<row r="1"><c r="A1" t="inlineStr"><is><t>nom_complet</t></is></c><c r="B1" t="inlineStr"><is><t>groupe</t></is></c></row>'
        .'<row r="2"><c r="A2" t="inlineStr"><is><t>Rose</t></is></c><c r="F2" t="inlineStr"><is><t>loin</t></is></c></row>';
    file_put_contents($path, Spreadsheets::xlsxWithSheetXml($rows, '', '<dimension ref="A1:XFD100"/>'));

    $lines = iterator_to_array((new VoterFileReader)->lines($path));

    expect($lines)->toHaveCount(2)
        ->and(count($lines[2]))->toBe(2)
        ->and($lines[2][0])->toBe('Rose');
    unlink($path);
});

it('gives a header of more than 50 columns whole, so that the check can refuse it [NFR-SEC-06]', function () {
    $path = limitsTemp();
    file_put_contents($path, Spreadsheets::csv([array_map(fn ($i) => "c{$i}", range(1, 60)), ['Rose']]));

    $lines = iterator_to_array((new VoterFileReader)->lines($path));

    expect(count($lines[1]))->toBe(60)->and(count($lines[2]))->toBeLessThanOrEqual(50);
    unlink($path);
});

it('ends the check after 11 000 lines read, blank ones included, and leaves no temporary folder [FR-VOT-04]', function () {
    [$uuid, $import] = limitsCheck("nom_complet,groupe\n".str_repeat(",\n", 11000));

    expect($import['status'])->toBe('failed')
        ->and($import['failure'])->toBe('too_many_rows')
        ->and(is_dir(ImportTempFolder::path($uuid)))->toBeFalse();
});

it('accepts a file of exactly 11 000 lines [FR-VOT-04]', function () {
    [, $import] = limitsCheck("nom_complet,groupe\nRose,4e\n".str_repeat(",\n", 10998));

    expect($import['status'])->toBe('checked')->and($import['total_rows'])->toBe(1);
});

it('keeps each value cut to its field maximum plus one, after judging the whole text [NFR-SEC-06]', function () {
    [, , $rows] = limitsCheck(Spreadsheets::csv([
        Spreadsheets::HEADER,
        [str_repeat('é', 400), str_repeat('G', 400), str_repeat('I', 400), str_repeat('e', 400).'@example.ht', str_repeat('1', 400)],
    ]));

    expect(mb_strlen($rows[0]['full_name']))->toBe(151)
        ->and(mb_strlen($rows[0]['group_name']))->toBe(101)
        ->and(mb_strlen($rows[0]['identifier']))->toBe(51)
        ->and(mb_strlen($rows[0]['email']))->toBe(256)
        ->and(mb_strlen($rows[0]['phone']))->toBe(31)
        ->and(json_decode($rows[0]['raw'], true)['full_name'])->toHaveLength(151)
        ->and(json_decode($rows[0]['reasons'], true))->toContain('full_name_max', 'group_max', 'identifier_max', 'email_max', 'phone_max');
});

it('creates, removes and ages out the temporary folders of the checks [NFR-PERF-03]', function () {
    $uuid = Str::uuid()->toString();
    $other = Str::uuid()->toString();

    $path = ImportTempFolder::make($uuid);
    file_put_contents($path.'/part', 'x');
    $stale = ImportTempFolder::make($other);
    touch($stale, time() - 2 * 86400);

    expect(is_dir($path))->toBeTrue()
        ->and(ImportTempFolder::removeOlderThan(86400))->toBeGreaterThanOrEqual(1)
        ->and(is_dir($stale))->toBeFalse()
        ->and(is_dir($path))->toBeTrue();

    ImportTempFolder::remove($uuid);

    expect(is_dir($path))->toBeFalse();
    expect(fn () => ImportTempFolder::path('../etc'))->toThrow(InvalidArgumentException::class);
});

it('removes the temporary folder when the job fails [NFR-PERF-03]', function () {
    $uuid = Str::uuid()->toString();
    $path = ImportTempFolder::make($uuid);
    file_put_contents($path.'/part', 'x');

    (new CheckVoterImport($uuid))->failed(new RuntimeException('killed'));

    expect(is_dir($path))->toBeFalse();
});

it('is the one guard for every export [NFR-SEC-06]', function () {
    expect(FormulaGuard::guard('=A1'))->toBe("'=A1")->and(FormulaGuard::guard('Rose'))->toBe('Rose');
});
