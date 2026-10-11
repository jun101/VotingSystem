<?php

namespace App\Actions\Credentials;

use App\Exceptions\ApiException;
use App\Models\Election;
use App\Models\GeneratedFile;
use App\Models\Institution;
use App\Models\Voter;
use App\Models\VoterGroup;
use App\Support\Credentials\Slip;
use App\Support\Credentials\SlipSheet;
use App\Support\Credentials\SlipWriter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * The job's work for one queued file: in one transaction, with the election's row locked first,
 * it gives a code to the voters of the file's scope who still have none (at most the file's
 * count), writes their slips, stores the encrypted PDF and marks the file `ready`. Any failure
 * rolls the codes back and marks the file `failed`: a code that no file shows is never kept.
 *
 * Nobody is signed in when it runs: every record is read by its own key, the institution named
 * by the file. Nothing of a code, a name or a request is logged: an outcome and a count only.
 */
final class GenerateSlipFile
{
    /** The group printed on the slips of the voters with no group. */
    public const NO_GROUP = 'Sans groupe';

    public function __construct(
        private readonly SlipWriter $writer,
        private readonly WriteCredential $credentials,
    ) {}

    public function __invoke(string $fileUuid): void
    {
        // The job has no signed-in user: the file is found by its own UUID.
        $file = GeneratedFile::withoutInstitutionScope()->where('uuid', $fileUuid)->first();

        if ($file === null || $file->status !== GeneratedFile::STATUS_QUEUED) {
            return;
        }

        $stored = null;
        $served = 0;

        try {
            DB::transaction(function () use ($file, &$stored, &$served): void {
                $served = $this->write($file, $stored);
            });
        } catch (Throwable) {
            // The exception is not logged: it may name a path or quote a value.
            $this->undo($file, $stored);

            return;
        }

        Log::info('credentials.file', ['outcome' => 'ready', 'voters' => $served]);
    }

    /** Marks a file failed, and removes the stored bytes of a transaction that did not commit. */
    public function fail(string $fileUuid): void
    {
        // The same reason as above: nobody is signed in.
        $marked = GeneratedFile::withoutInstitutionScope()
            ->where('uuid', $fileUuid)
            ->where('status', GeneratedFile::STATUS_QUEUED)
            ->update([
                'status' => GeneratedFile::STATUS_FAILED,
                'failure' => GeneratedFile::FAILURE_GENERATION,
                'updated_at' => Carbon::now('UTC'),
            ]);

        if ($marked > 0) {
            Log::info('credentials.file', ['outcome' => 'failed']);
        }
    }

    private function undo(GeneratedFile $file, ?string $stored): void
    {
        if ($stored !== null) {
            try {
                Storage::disk('credentials')->delete($stored);
            } catch (Throwable) {
                // Nothing refers to it: an orphan under a random name at worst.
            }
        }

        $this->fail($file->uuid);
    }

    /** The voters served. Runs inside the transaction. */
    private function write(GeneratedFile $file, ?string &$stored): int
    {
        // Locked first: no run, reissue or voter write of this election happens meanwhile.
        $election = Election::withoutInstitutionScope()->whereKey($file->election_id)->lockForUpdate()->first();

        if ($election === null) {
            throw new ApiException(404, 'not_found');
        }

        $election->assertCodesOpen();

        // Read again under the lock: another job may have taken the file.
        $current = GeneratedFile::withoutInstitutionScope()->whereKey($file->id)->lockForUpdate()->first();

        if ($current === null || $current->status !== GeneratedFile::STATUS_QUEUED) {
            return 0;
        }

        $group = null;

        if ($current->voter_group_id !== null) {
            // Queue job: nobody is signed in, so the scope reads no institution; the file's own is used.
            $group = VoterGroup::withoutInstitutionScope()->whereKey($current->voter_group_id)->first();
        }

        // Queue job: nobody is signed in, so the scope reads no institution; the election is the bound.
        $voters = Voter::withoutInstitutionScope()
            ->where('voters.election_id', $election->id)
            ->when(
                $current->voter_group_id === null,
                fn ($query) => $query->whereNull('voters.voter_group_id'),
                fn ($query) => $query->where('voters.voter_group_id', $current->voter_group_id),
            )
            ->whereNotExists(function (Builder $credentials): void {
                $credentials->select(DB::raw('1'))->from('credentials')->whereColumn('credentials.voter_id', 'voters.id');
            })
            ->orderBy('voters.full_name')
            ->orderBy('voters.id')
            ->limit($current->voter_count)
            ->get(['voters.id', 'voters.full_name']);

        $slips = [];

        foreach ($voters as $voter) {
            $code = ($this->credentials)($election, $voter->id, $current->user_id);
            $slips[] = new Slip($voter->full_name, $group->name ?? self::NO_GROUP, $code);
        }

        // The institution is not a tenant model: no scope to lift.
        $institution = Institution::query()->whereKey($election->institution_id)->value('name');

        $pdf = $this->writer->write(new SlipSheet(
            institutionName: is_string($institution) ? $institution : '',
            electionTitle: $election->title,
            votingAddress: rtrim(config()->string('app.url'), '/').'/vote/'.$election->uuid,
            startsAt: $election->starts_at,
            endsAt: $election->ends_at,
            timezone: $election->timezone,
            language: $election->language,
            groupName: $group?->name,
            part: $current->part,
            parts: $current->parts,
            slips: $slips,
        ));

        $name = Uuid::uuid4()->toString();
        // Throws when the disk cannot be written: the transaction rolls the codes back.
        Storage::disk('credentials')->put($name, Crypt::encryptString($pdf));
        $stored = $name;

        $current->forceFill([
            'status' => GeneratedFile::STATUS_READY,
            'failure' => null,
            'stored_file' => $name,
            'size_bytes' => strlen($pdf),
            'voter_count' => count($slips),
        ])->save();

        return count($slips);
    }
}
