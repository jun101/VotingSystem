<?php

namespace App\Jobs;

use App\Jobs\Middleware\ThrottleCredentialEmails;
use App\Mail\CredentialMail;
use App\Models\Credential;
use App\Models\Election;
use App\Models\Institution;
use App\Models\Voter;
use App\Support\Credentials\AccessCode;
use App\Support\Credentials\AddressSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one voter their personal link and code (FR-CRED-04). The payload is the voter's UUID, the
 * election's UUID and the code, and nothing else: no model, no address (read when the message is
 * sent), and it is encrypted since it holds a code. It never logs an address, a name, a code or
 * the transport's text: an outcome and an exception class at most.
 *
 * A transport error is retried up to `credentials.email_tries` times with a growing delay; on the
 * last one the voter's credential is marked `failed` with a short fixed reason and the job
 * returns normally, so a mail error never breaks anything else. The count of failed sends is
 * kept in the cache (key: the voter and the code's hash) and not in the queue's attempts: a job
 * released by the rate limit must not use up a try of the message, and the time spent waiting never
 * gives a message up. A message is sent only while the voter's status is still `queued`, once (a
 * marker is set with a conditional update before the send), for an institution that is not
 * suspended, to the address the run was made for.
 */
class SendCredentialEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Unlimited attempts at the queue level: the count of real send attempts (and the 7-hour sweep) bound it. */
    public int $tries = 0;

    /** Seconds before a retry, by failed send (the last value is kept for the rest). */
    private const BACKOFF = [30, 120, 600];

    /** A failed-send counter does not outlive the message by long. */
    private const COUNTER_TTL_SECONDS = 86400;

    public function __construct(
        private readonly string $voterUuid,
        private readonly string $electionUuid,
        private readonly string $code,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleCredentialEmails];
    }

    /**
     * Seconds before the queue retries after an exception that the job did not handle itself.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return self::BACKOFF;
    }

    /** The voter this message is for: the throttle finds the institution from it. */
    public function voterUuid(): string
    {
        return $this->voterUuid;
    }

    public function handle(): void
    {
        try {
            $this->send();
        } catch (Throwable $e) {
            // Not a transport error (the database, the cache): a delay, never a spin, and counted as a try.
            $this->unexpected($e);
        }
    }

    private function send(): void
    {
        // Queue job: nobody is signed in, so the scope reads no institution; the UUIDs are the keys.
        $voter = Voter::withoutInstitutionScope()->where('uuid', $this->voterUuid)->first();
        // Queue job: nobody is signed in, so the scope reads no institution; the UUID is the key.
        $election = Election::withoutInstitutionScope()->where('uuid', $this->electionUuid)->first();

        // The voter was deleted meanwhile: nothing to do.
        if ($voter === null || $election === null || $voter->election_id !== $election->id) {
            Cache::forget($this->counterKey());

            return;
        }

        $hash = AccessCode::hash($this->code);

        // Queue job: nobody is signed in; the voter is the key.
        $credential = Credential::withoutInstitutionScope()->where('voter_id', $voter->id)->first();

        // The code was replaced meanwhile (a newer one is the one to send): this message is moot.
        if ($credential === null || ! hash_equals($credential->code_hash, $hash)) {
            Cache::forget($this->counterKey());

            return;
        }

        // Sent, failed or cleared already (a second delivery of the same job, a sweep): nothing more to send.
        if ($credential->email_status !== Credential::EMAIL_QUEUED || $credential->email_sent_at !== null) {
            Cache::forget($this->counterKey());

            return;
        }

        $refused = $this->refusal($voter, $election, $credential);

        if ($refused !== null) {
            Cache::forget($this->counterKey());
            $this->mark($credential->id, $hash, Credential::EMAIL_FAILED, $refused);

            return;
        }

        // The claim: one worker sets the marker while the status is still `queued`; a second delivery finds it set.
        if (! $this->claim($credential->id, $hash)) {
            Cache::forget($this->counterKey());

            return;
        }

        try {
            Mail::to($voter->email)
                ->locale($election->language)
                ->send(new CredentialMail(
                    voterName: $voter->full_name,
                    electionTitle: $election->title,
                    electionUuid: $election->uuid,
                    institution: $this->institutionName($election),
                    startsAt: $election->starts_at,
                    endsAt: $election->ends_at,
                    timezone: $election->timezone,
                    code: $this->code,
                ));
        } catch (Throwable $e) {
            $this->transportFailed($credential->id, $hash, $e);

            return;
        }

        Cache::forget($this->counterKey());
        $this->mark($credential->id, $hash, Credential::EMAIL_SENT, null);
    }

    /** The fixed reason code for a message that must not be sent, or null when it may be. */
    private function refusal(Voter $voter, Election $election, Credential $credential): ?string
    {
        if ($voter->email === null) {
            return 'no_address';
        }

        // The institution is not a tenant model: no scope to lift.
        $institution = Institution::query()->whereKey($election->institution_id)->first();

        if ($institution === null || $institution->isSuspended()) {
            return 'institution_suspended';
        }

        // The run was made for the address the voter had then.
        if ($credential->email_hash !== null && ! hash_equals($credential->email_hash, AddressSnapshot::of($voter->email))) {
            return 'address_changed';
        }

        if (! $election->isCodesOpen()) {
            return 'election_locked';
        }

        return null;
    }

    /** The queue gave the message up (expired, killed): the voter's status must not stay `queued`. */
    public function failed(?Throwable $exception): void
    {
        try {
            // Queue job: nobody is signed in; the voter's UUID is the key.
            $voter = Voter::withoutInstitutionScope()->where('uuid', $this->voterUuid)->first();

            if ($voter === null) {
                return;
            }

            // Queue job: nobody is signed in; the voter is the key.
            $credential = Credential::withoutInstitutionScope()->where('voter_id', $voter->id)->first();

            if ($credential !== null) {
                $this->mark($credential->id, AccessCode::hash($this->code), Credential::EMAIL_FAILED, Credential::ERROR_SEND_FAILED);
            }
        } catch (Throwable) {
            // Nothing more can be done: the status stays what it was.
        }
    }

    /**
     * Writes the marker of an attempt in progress: the row is `queued` and unmarked, for this code only.
     *
     * @return bool true when this worker holds the message
     */
    private function claim(int $credentialId, string $hash): bool
    {
        // Queue job: nobody is signed in; the credential, its hash and its status are the keys.
        return Credential::withoutInstitutionScope()
            ->whereKey($credentialId)
            ->where('code_hash', $hash)
            ->where('email_status', Credential::EMAIL_QUEUED)
            ->whereNull('email_sent_at')
            ->update(['email_sent_at' => Carbon::now('UTC')]) === 1;
    }

    /** The marker goes back so the retry can claim the message again. */
    private function unclaim(int $credentialId, string $hash): void
    {
        // Queue job: nobody is signed in; the credential, its hash and its status are the keys.
        Credential::withoutInstitutionScope()
            ->whereKey($credentialId)
            ->where('code_hash', $hash)
            ->where('email_status', Credential::EMAIL_QUEUED)
            ->update(['email_sent_at' => null]);
    }

    private function transportFailed(int $credentialId, string $hash, Throwable $e): void
    {
        $key = $this->counterKey();
        Cache::add($key, 0, self::COUNTER_TTL_SECONDS);
        $failures = (int) Cache::increment($key);
        $tries = max(1, Config::integer('credentials.email_tries', 3));

        if ($failures < $tries) {
            Log::info('credentials.email', ['outcome' => 'retry', 'class' => $e::class, 'failures' => $failures]);
            $this->unclaim($credentialId, $hash);
            $this->release(self::BACKOFF[min($failures, count(self::BACKOFF)) - 1]);

            return;
        }

        Cache::forget($key);
        $this->mark($credentialId, $hash, Credential::EMAIL_FAILED, Credential::ERROR_SEND_FAILED);
        Log::info('credentials.email', ['outcome' => 'failed', 'class' => $e::class]);
    }

    /** An exception that is not the transport's: released with a delay, and given up after the same number of tries. */
    private function unexpected(Throwable $e): void
    {
        Log::info('credentials.email', ['outcome' => 'error', 'class' => $e::class]);

        try {
            $key = $this->counterKey();
            Cache::add($key, 0, self::COUNTER_TTL_SECONDS);
            $failures = (int) Cache::increment($key);

            if ($failures >= max(1, Config::integer('credentials.email_tries', 3))) {
                Cache::forget($key);
                $this->failed($e);

                return;
            }

            $this->release(self::BACKOFF[min($failures, count(self::BACKOFF)) - 1]);
        } catch (Throwable) {
            // The cache is gone too: the delay alone keeps the job from spinning.
            $this->release(self::BACKOFF[0]);
        }
    }

    /** Writes the status of the message, only while the credential still holds this code. */
    private function mark(int $credentialId, string $hash, string $status, ?string $error): void
    {
        // Queue job: nobody is signed in; the credential and its hash are the keys.
        Credential::withoutInstitutionScope()
            ->whereKey($credentialId)
            ->where('code_hash', $hash)
            ->update([
                'email_status' => $status,
                'email_sent_at' => $status === Credential::EMAIL_SENT ? Carbon::now('UTC') : null,
                'email_error' => $error,
                // The address kept with the message has done its work.
                'email_hash' => null,
            ]);
    }

    /** The cache key of the failed sends of this message: the voter's UUID and the code's hash, never the code. */
    private function counterKey(): string
    {
        return 'credential-email-failures:'.$this->voterUuid.':'.bin2hex(AccessCode::hash($this->code));
    }

    private function institutionName(Election $election): string
    {
        // The institution is not a tenant model: no scope to lift.
        $name = Institution::query()->whereKey($election->institution_id)->value('name');

        return is_string($name) ? $name : '';
    }
}
