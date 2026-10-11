<?php

/*
 * The job of one slip file (review of slice 10): a collision redraws, a voter coded meanwhile is
 * skipped, a file with nobody left is removed, a failing file does not stop the next one, and
 * the compensation of a failed PDF leaves a code reissued meanwhile alone.
 */

use App\Actions\Credentials\GenerateSlipFile;
use App\Actions\Credentials\WriteCredential;
use App\Jobs\GenerateSlipFileJob;
use App\Models\Election;
use App\Support\Credentials\SlipSheet;
use App\Support\Credentials\SlipWriter;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\Codes;

beforeEach(function () {
    Accounts::reset();
    Codes::useTempDisk();
});

/** A writer that runs `$during` with the sheet, then answers a small PDF (or lets the closure throw). */
function slipJobWriter(?Closure $during = null): SlipWriter
{
    return new class($during) implements SlipWriter
    {
        public function __construct(private readonly ?Closure $during) {}

        public function write(SlipSheet $sheet): string
        {
            if ($this->during !== null) {
                ($this->during)($sheet);
            }

            return '%PDF-1.4 '.count($sheet->slips).' slips';
        }
    };
}

/** An institution and a draft election. Returns [election uuid]. */
function slipJobElection(): string
{
    $user = Accounts::user(['name' => 'Alice Owner', 'email' => 'alice.'.bin2hex(random_bytes(4)).'@example.test']);

    return Accounts::plantElection(['institution' => $user['institution'], 'starts_at' => '2030-03-01 12:00:00', 'ends_at' => '2030-03-05 19:00:00']);
}

/** The hash stored for a voter (its uuid), or null. */
function slipJobHash(string $voter): ?string
{
    $row = Accounts::credentialRow($voter);

    return $row === null ? null : $row['code_hash'];
}

it('draws a new code when the hash of the first one is already taken [FR-CRED-01] (slip job 1)', function () {
    $election = slipJobElection();
    $served = Accounts::plantVoter(['election' => $election, 'full_name' => 'Aline Duval']);
    $other = Accounts::plantVoter(['election' => $election, 'full_name' => 'Bruno Pierre']);
    Accounts::plantCredential(['voter' => $served, 'code' => 'ABCDE-FGHJK']);
    $file = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'voter_count' => 1]);

    $draws = ['ABCDE-FGHJK', 'MNPQR-STUVW'];
    $calls = 0;
    app()->instance(WriteCredential::class, new WriteCredential(function () use (&$draws, &$calls): string {
        $calls++;

        return array_shift($draws) ?? 'XXXXX-XXXXX';
    }));
    app()->instance(SlipWriter::class, slipJobWriter());

    app(GenerateSlipFile::class)($file);

    expect($calls)->toBe(2)
        ->and(slipJobHash($other))->toBe(Codes::hash('MNPQR-STUVW'))
        ->and(Accounts::generatedFileRows($election)[0]['status'])->toBe('ready');
});

it('skips a voter who got a code between the request and the job and lowers the count [FR-CRED-01] (slip job 2)', function () {
    $election = slipJobElection();
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Aline Duval']);
    $middle = Accounts::plantVoter(['election' => $election, 'full_name' => 'Bruno Pierre']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Carole Joseph']);
    $file = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'voter_count' => 3]);
    $code = Accounts::plantCredential(['voter' => $middle]);
    app()->instance(SlipWriter::class, slipJobWriter());

    app(GenerateSlipFile::class)($file);

    $rows = Accounts::generatedFileRows($election);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['status'])->toBe('ready')
        ->and($rows[0]['voter_count'])->toBe(2)
        ->and(Accounts::credentialRows($election))->toHaveCount(3)
        ->and(slipJobHash($middle))->toBe(Codes::hash($code));
});

it('removes a file whose voters were all served meanwhile [FR-CRED-01] (slip job 3)', function () {
    $election = slipJobElection();
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Aline Duval']);
    Accounts::plantCredential(['voter' => $voter]);
    $file = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'voter_count' => 1]);
    app()->instance(SlipWriter::class, slipJobWriter());

    app(GenerateSlipFile::class)($file);

    expect(Accounts::generatedFileRows($election))->toBe([])
        ->and(Codes::storedFiles())->toBe([]);
});

it('goes on with the next file when one fails, and when one is lost [FR-CRED-04] (slip job 4)', function () {
    $election = slipJobElection();
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Aline Duval']);
    $fileOne = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'voter_count' => 1]);
    $fileTwo = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'voter_count' => 1]);

    // The first file throws, the second is written (both take the voters with no group).
    $writes = 0;
    app()->instance(SlipWriter::class, slipJobWriter(function () use (&$writes): void {
        $writes++;

        if ($writes === 1) {
            throw new RuntimeException('The engine broke.');
        }
    }));

    GenerateSlipFileJob::dispatchSync($fileOne);

    $rows = collect(Accounts::generatedFileRows($election))->keyBy('uuid');
    expect($rows[$fileOne]['status'])->toBe('failed')
        ->and($rows[$fileOne]['failure'])->toBe('generation_failed')
        ->and($rows[$fileTwo]['status'])->toBe('ready')
        // The failed file's code was taken back; the next file served the same voter.
        ->and(Accounts::credentialRows($election))->toHaveCount(1);

    // A job lost by the worker (timeout): failed() marks it and still queues the next one.
    $election = slipJobElection();
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Carole Joseph']);
    $lost = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'voter_count' => 1]);
    $next = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'voter_count' => 1]);
    app()->instance(SlipWriter::class, slipJobWriter());

    (new GenerateSlipFileJob($lost))->failed(new RuntimeException('timeout'));

    $rows = collect(Accounts::generatedFileRows($election))->keyBy('uuid');
    expect($rows[$lost]['status'])->toBe('failed')
        ->and($rows[$next]['status'])->toBe('ready')
        ->and(slipJobHash($voter))->not->toBeNull();
});

it('takes back only the codes of a failed file and leaves a code reissued meanwhile [FR-CRED-03] (slip job 5)', function () {
    $election = slipJobElection();
    $reissued = Accounts::plantVoter(['election' => $election, 'full_name' => 'Aline Duval']);
    $plain = Accounts::plantVoter(['election' => $election, 'full_name' => 'Bruno Pierre']);
    $file = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'voter_count' => 2]);
    $newCode = 'MNPQR-STUVW';

    // Between the codes and the end of the PDF, a manager reissues the first voter's code: then the PDF fails.
    app()->instance(SlipWriter::class, slipJobWriter(function () use ($reissued, $newCode): void {
        $db = DB::connection(useMigratorConnection());
        $db->table('credentials')
            ->where('voter_id', $db->table('voters')->where('uuid', $reissued)->value('id'))
            ->update(['code_hash' => Codes::hash($newCode), 'reissue_count' => 1]);

        throw new RuntimeException('The engine broke.');
    }));

    app(GenerateSlipFile::class)($file);

    $rows = Accounts::generatedFileRows($election);
    expect($rows[0]['status'])->toBe('failed')
        ->and($rows[0]['stored_file'])->toBeNull()
        ->and(slipJobHash($reissued))->toBe(Codes::hash($newCode))
        ->and(slipJobHash($plain))->toBeNull()
        ->and(Codes::storedFiles())->toBe([]);
});
