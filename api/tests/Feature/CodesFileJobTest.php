<?php

/*
 * The self-distribution file of a run (slice 11b): the partial the slip jobs build is encrypted, is
 * removed with the row whatever way the row goes (purge, stale run, election closed, failure), a
 * run in which nobody was served leaves no file, and an incomplete file is never written.
 */

use App\Actions\Credentials\GenerateCodesFile;
use App\Actions\Credentials\StartCredentialRun;
use App\Models\Election;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;
use Tests\Support\Accounts;
use Tests\Support\Codes;

beforeEach(function () {
    Accounts::reset();
    Codes::useTempDisk();
});

/** An election in the given status. Returns [election uuid]. */
function codesJobElection(string $status = 'draft'): string
{
    $user = Accounts::user(['name' => 'Alice Owner', 'email' => 'alice.'.bin2hex(random_bytes(4)).'@example.test']);

    return Accounts::plantElection(['institution' => $user['institution'], 'status' => $status]);
}

/** A queued codes file with a partial of the given rows on the disk. Returns [file uuid, stored name]. */
function codesJobFile(string $election, array $rows, array $o = []): array
{
    $file = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'voter_count' => 1] + $o);
    $stored = Uuid::uuid4()->toString();
    Storage::disk('credentials')->put($stored, Crypt::encryptString(json_encode($rows, JSON_UNESCAPED_UNICODE)));
    DB::connection(useMigratorConnection())->table('generated_files')->where('uuid', $file)->update(['kind' => 'codes_csv', 'stored_file' => $stored]);

    return [$file, $stored];
}

it('removes the partial of a codes file with its row when the election is closed [FR-CRED-05] (codes file 1)', function () {
    $election = codesJobElection();
    codesJobFile($election, [['Rose', '', 'ABCDE-FGHJK', 'http://x/vote/y#ABCDE-FGHJK']]);
    expect(Codes::storedFiles())->toHaveCount(1);
    Accounts::setElectionStatus($election, 'closed');

    expect(Artisan::call('files:purge'))->toBe(0);

    expect(Accounts::generatedFileRows($election))->toBe([])
        ->and(Codes::storedFiles())->toBe([]);
});

it('fails a codes file stuck queued and removes its partial [FR-CRED-05] (codes file 2)', function () {
    $election = codesJobElection();
    [$file] = codesJobFile($election, [['Rose', '', 'ABCDE-FGHJK', 'http://x']], ['created_at' => now('UTC')->subMinutes(61)->toDateTimeString()]);

    expect(Artisan::call('files:purge'))->toBe(0);

    $row = collect(Accounts::generatedFileRows($election))->firstWhere('uuid', $file);
    expect($row['status'])->toBe('failed')
        ->and($row['stored_file'])->toBeNull()
        ->and(Codes::storedFiles())->toBe([]);
});

it('fails a stale codes file when a new run starts and removes its partial [FR-CRED-05] (codes file 3)', function () {
    $election = codesJobElection();
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose Désir']);
    [$file] = codesJobFile($election, [['Rose', '', 'ABCDE-FGHJK', 'http://x']], ['created_at' => Carbon::now('UTC')->subMinutes(20)->toDateTimeString()]);

    $model = Election::withoutInstitutionScope()->where('uuid', $election)->firstOrFail();
    // The scope of the tenant models reads the institution of the signed-in user.
    Auth::guard()->setUser(User::withoutGlobalScopes()->where('institution_id', $model->institution_id)->firstOrFail());
    $run = app(StartCredentialRun::class)($model, 'all', null, null, false, null, 'csv');

    $rows = collect(Accounts::generatedFileRows($election))->keyBy('uuid');
    expect($rows[$file]['status'])->toBe('failed')
        ->and($rows[$file]['stored_file'])->toBeNull()
        ->and(array_map(fn ($f) => $f->kind, $run['files']))->toBe(['slips_pdf', 'codes_csv'])
        ->and(Codes::storedFiles())->toBe([]);
});

it('removes the codes file of a run in which nobody was served [FR-CRED-04] (codes file 4)', function () {
    $election = codesJobElection();
    $file = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'voter_count' => 1]);
    DB::connection(useMigratorConnection())->table('generated_files')->where('uuid', $file)->update(['kind' => 'codes_xlsx']);

    app(GenerateCodesFile::class)($file);

    expect(Accounts::generatedFileRows($election))->toBe([]);
});

it('fails rather than write a file that does not hold every voter the slips served [FR-CRED-04] (codes file 5)', function () {
    $election = codesJobElection();
    [$file] = codesJobFile($election, [['Rose', '', 'ABCDE-FGHJK', 'http://x']]);
    // A ready slip file of the same run that served two voters: the partial holds one row.
    $slips = Accounts::plantGeneratedFile(['election' => $election, 'voter_count' => 2]);
    $at = collect(Accounts::generatedFileRows($election))->firstWhere('uuid', $file)['created_at'];
    DB::connection(useMigratorConnection())->table('generated_files')->where('uuid', $slips)->update(['created_at' => $at]);

    app(GenerateCodesFile::class)($file);

    $row = collect(Accounts::generatedFileRows($election))->firstWhere('uuid', $file);
    expect($row['status'])->toBe('failed')
        ->and($row['failure'])->toBe('generation_failed')
        ->and($row['stored_file'])->toBeNull()
        // Only the slip file's own copy is left.
        ->and(Codes::storedFiles())->toHaveCount(1);
});

it('writes the file, replaces the partial and sorts the voters with no group last [FR-CRED-04] (codes file 6)', function () {
    $election = codesJobElection();
    [$file, $partial] = codesJobFile($election, [
        ['Zoé', '', 'AAAAA-BBBBB', 'http://x/1'],
        ['Éric', 'Ère', 'CCCCC-DDDDD', 'http://x/2'],
        ['Anne', 'Zèbre', 'EEEEE-FFFFF', 'http://x/3'],
        ['Bob', 'Ère', 'GGGGG-HHHHH', 'http://x/4'],
    ], ['voter_count' => 4]);
    $slips = Accounts::plantGeneratedFile(['election' => $election, 'voter_count' => 4]);
    $at = collect(Accounts::generatedFileRows($election))->firstWhere('uuid', $file)['created_at'];
    DB::connection(useMigratorConnection())->table('generated_files')->where('uuid', $slips)->update(['created_at' => $at]);

    app(GenerateCodesFile::class)($file);

    $row = collect(Accounts::generatedFileRows($election))->firstWhere('uuid', $file);
    expect($row['status'])->toBe('ready')
        ->and($row['voter_count'])->toBe(4)
        ->and($row['stored_file'])->not->toBe($partial)
        ->and(Storage::disk('credentials')->exists($partial))->toBeFalse();
    $csv = Crypt::decryptString(Storage::disk('credentials')->get($row['stored_file']));
    $names = array_map(fn (string $line): string => str_getcsv($line)[0], array_slice(explode("\r\n", trim(substr($csv, 3))), 1));
    expect($names)->toBe(['Bob', 'Éric', 'Anne', 'Zoé']);
});
