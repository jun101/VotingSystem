<?php

namespace App\Actions\Credentials;

use App\Exceptions\ApiException;
use App\Models\Credential;
use App\Models\Election;
use App\Models\GeneratedFile;
use App\Models\Institution;
use App\Models\User;
use App\Models\Voter;
use App\Support\Credentials\Slip;
use App\Support\Credentials\SlipSheet;
use App\Support\Credentials\SlipWriter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Gives one voter a code (the first, or one that replaces a lost slip) and writes a one-slip
 * file, in one transaction with the election's row locked first, then the voter's credential
 * row. The file is written inside it: when it cannot be, the old code stays and the answer is a
 * 500. The code is returned for the response body, and goes nowhere else.
 */
final class ReissueCredential
{
    public function __construct(
        private readonly SlipWriter $writer,
        private readonly WriteCredential $credentials,
    ) {}

    /**
     * @return array{code: string, reissue_count: int, file: GeneratedFile}
     *
     * @throws ApiException 404, 409 `election_codes_locked`, or 500 `server_error`
     */
    public function __invoke(Voter $voter, ?User $user): array
    {
        $stored = null;

        try {
            return DB::transaction(function () use ($voter, $user, &$stored): array {
                return $this->run($voter, $user, $stored);
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable) {
            // The exception is not reported: only the outcome is logged.
            if ($stored !== null) {
                try {
                    Storage::disk('credentials')->delete($stored);
                } catch (Throwable) {
                    // Nothing refers to the name: an orphan under a random name at worst.
                }
            }

            Log::info('credentials.reissue', ['outcome' => 'failed']);

            throw new ApiException(500, 'server_error');
        }
    }

    /**
     * @param-out string $stored
     *
     * @return array{code: string, reissue_count: int, file: GeneratedFile}
     */
    private function run(Voter $voter, ?User $user, ?string &$stored): array
    {
        // Locked first, as every write of the codes of an election.
        $election = Election::query()->whereKey($voter->election_id)->lockForUpdate()->first();

        if ($election === null) {
            throw new ApiException(404, 'not_found');
        }

        $election->assertCodesOpen();

        $current = Voter::query()->whereKey($voter->id)->with('group')->first();

        if ($current === null) {
            throw new ApiException(404, 'not_found');
        }

        // Slice 12 refuses here (409 `voter_has_voted`) once `participations` exists.

        $existing = Credential::query()->where('voter_id', $current->id)->lockForUpdate()->first();

        $code = ($this->credentials)($election, $current->id, $user?->id, $existing);

        // The row was updated in place: its count is the new one.
        $reissues = $existing === null ? 0 : $existing->reissue_count;

        $pdf = $this->writer->write(new SlipSheet(
            institutionName: $this->institutionName($election),
            electionTitle: $election->title,
            votingAddress: rtrim(config()->string('app.url'), '/').'/vote/'.$election->uuid,
            startsAt: $election->starts_at,
            endsAt: $election->ends_at,
            timezone: $election->timezone,
            language: $election->language,
            groupName: $current->group->name ?? null,
            part: 1,
            parts: 1,
            slips: [new Slip($current->full_name, $current->group->name ?? GenerateSlipFile::NO_GROUP, $code)],
        ));

        $name = Uuid::uuid4()->toString();
        Storage::disk('credentials')->put($name, Crypt::encryptString($pdf));
        $stored = $name;

        $file = new GeneratedFile;
        $file->institution_id = $election->institution_id;
        $file->election_id = $election->id;
        $file->user_id = $user?->id;
        $file->kind = GeneratedFile::KIND_SLIPS;
        $file->voter_group_id = $current->voter_group_id;
        $file->part = 1;
        $file->parts = 1;
        $file->voter_count = 1;
        $file->status = GeneratedFile::STATUS_READY;
        $file->failure = null;
        $file->stored_file = $name;
        $file->size_bytes = strlen($pdf);
        $file->expires_at = $election->ends_at;
        $file->save();
        $file->setRelation('group', $current->group);

        Log::info('credentials.reissue', ['outcome' => 'issued', 'reissue_count' => $reissues]);

        return ['code' => $code, 'reissue_count' => $reissues, 'file' => $file];
    }

    private function institutionName(Election $election): string
    {
        // The institution is not a tenant model: no scope to lift.
        $name = Institution::query()->whereKey($election->institution_id)->value('name');

        return is_string($name) ? $name : '';
    }
}
