<?php

namespace App\Actions\Credentials;

use App\Exceptions\ApiException;
use App\Jobs\SendCredentialEmail;
use App\Models\Credential;
use App\Models\Election;
use App\Models\GeneratedFile;
use App\Models\Institution;
use App\Models\User;
use App\Models\Voter;
use App\Models\Voting\Participation;
use App\Support\Credentials\AccessCode;
use App\Support\Credentials\EmailQuota;
use App\Support\Credentials\Slip;
use App\Support\Credentials\SlipSheet;
use App\Support\Credentials\SlipWriter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
     * @param  bool  $email  the new code is also emailed to the voter (in the background)
     * @return array{code: string, reissue_count: int, file: GeneratedFile, email: 'queued'|null}
     *
     * @throws ApiException 404, 403 `email_not_verified` (with `$email`), 409 `election_codes_locked`,
     *                      409 `email_quota_exceeded` (with the number left), or 500 `server_error`
     * @throws ValidationException 422 `email: no_address` (nothing is changed)
     */
    public function __invoke(Voter $voter, ?User $user, bool $email = false): array
    {
        $stored = null;

        // A person whose own address is unconfirmed cannot make the platform send mail in the institution's name.
        if ($email && $user !== null && $user->email_verified_at === null) {
            throw new ApiException(403, 'email_not_verified');
        }

        try {
            $result = DB::transaction(function () use ($voter, $user, $email, &$stored): array {
                return $this->run($voter, $user, $email, $stored);
            });
        } catch (ApiException|ValidationException $e) {
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

        // After the commit: the message is queued only for a code that is kept.
        $queued = false;

        if ($result['mail'] !== null) {
            try {
                SendCredentialEmail::dispatch($result['mail']['voter'], $result['mail']['election'], $result['code']);
                $queued = true;
            } catch (Throwable $e) {
                Log::info('credentials.email', ['outcome' => 'dispatch_failed', 'class' => $e::class]);
                $this->markFailed($result['mail']['key'], $result['mail']['hash']);
            }
        }

        return [
            'code' => $result['code'],
            'reissue_count' => $result['reissue_count'],
            'file' => $result['file'],
            'email' => $queued ? 'queued' : null,
        ];
    }

    /** The queue could not be reached: the status must not stay `queued`. */
    private function markFailed(int $voterKey, string $hash): void
    {
        try {
            // Nobody else can be writing this row now, and the hash makes sure it is still this code.
            Credential::query()
                ->where('voter_id', $voterKey)
                ->where('code_hash', $hash)
                ->update(['email_status' => Credential::EMAIL_FAILED, 'email_error' => Credential::ERROR_SEND_FAILED]);
        } catch (Throwable) {
            // The status stays `queued`: the voters list shows it.
        }
    }

    /**
     * @param-out string $stored
     *
     * @return array{code: string, reissue_count: int, file: GeneratedFile, mail: array{voter: string, election: string, key: int, hash: string}|null}
     */
    private function run(Voter $voter, ?User $user, bool $email, ?string &$stored): array
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

        // A voter who has voted keeps the code that voted: nothing changes (FR-CRED-03). Read under the
        // election's lock, which a vote also takes (shared) before it writes its participation.
        if (Participation::query()->where('election_id', $election->id)->where('voter_id', $current->id)->exists()) {
            throw new ApiException(409, 'voter_has_voted');
        }

        // Read under the lock: an address removed since the page was drawn is seen here.
        if ($email && $current->email === null) {
            throw ValidationException::withMessages(['email' => ['no_address']]);
        }

        $existing = Credential::query()->where('voter_id', $current->id)->lockForUpdate()->first();

        if ($email) {
            // The institution's row after the election's: two runs of two elections cannot both pass.
            Institution::query()->whereKey($election->institution_id)->lockForUpdate()->first();

            // A code of the last 24 hours already counted as an email that this one replaces: no net addition.
            $counted = $existing !== null
                && in_array($existing->email_status, [Credential::EMAIL_QUEUED, Credential::EMAIL_SENT], true)
                && $existing->issued_at->gte(now('UTC')->subDay());

            if (! $counted && EmailQuota::left($election->institution_id) < 1) {
                throw new ApiException(409, 'email_quota_exceeded', count: 0);
            }
        }

        $code = ($this->credentials)($election, $current->id, $user?->id, $existing, $email, $email ? $current->email : null);

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
        // A reissue is a run of its own.
        $file->run = Uuid::uuid4()->toString();
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

        return [
            'code' => $code,
            'reissue_count' => $reissues,
            'file' => $file,
            'mail' => $email ? ['voter' => $current->uuid, 'election' => $election->uuid, 'key' => $current->id, 'hash' => AccessCode::hash($code)] : null,
        ];
    }

    private function institutionName(Election $election): string
    {
        // The institution is not a tenant model: no scope to lift.
        $name = Institution::query()->whereKey($election->institution_id)->value('name');

        return is_string($name) ? $name : '';
    }
}
