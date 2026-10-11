<?php

namespace App\Actions\Credentials;

use App\Models\Credential;
use App\Models\Election;
use App\Support\Credentials\AccessCode;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Gives a voter a new code: draws one, stores its hash (a new row, or the voter's row with
 * `reissue_count` + 1) and returns the code in clear. A hash that collides with another code of
 * the election (unique with the election) draws again. The caller holds the election's row lock.
 */
final class WriteCredential
{
    /** Draws before giving up (a collision is a one-in-ten-trillion event for 10 000 voters). */
    private const ATTEMPTS = 5;

    /** @var Closure(): string */
    private readonly Closure $generator;

    /**
     * @param  (Closure(): string)|null  $generator  how a code is drawn; the random generator when null (a test passes its own)
     */
    public function __construct(?Closure $generator = null)
    {
        $this->generator = $generator ?? AccessCode::generate(...);
    }

    /**
     * @param  Credential|null  $existing  the voter's current row, locked by the caller
     * @param  bool  $queueEmail  the code will be emailed: the status is `queued`, written with the code
     */
    public function __invoke(Election $election, int $voterId, ?int $userId, ?Credential $existing = null, bool $queueEmail = false): string
    {
        $reissues = $existing === null ? 0 : $existing->reissue_count + 1;

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $code = ($this->generator)();
            $hash = AccessCode::hash($code);

            $row = $existing ?? new Credential;

            if ($existing === null) {
                $row->institution_id = $election->institution_id;
                $row->election_id = $election->id;
                $row->voter_id = $voterId;
            }

            $row->code_hash = $hash;
            $row->issued_at = Carbon::now('UTC');
            $row->issued_by_user_id = $userId;
            $row->reissue_count = $reissues;

            if ($queueEmail) {
                $row->email_status = Credential::EMAIL_QUEUED;
                $row->email_sent_at = null;
                $row->email_error = null;
            }

            try {
                // A refused value leaves nothing of the statement, and the caller's transaction goes on.
                $row->save();
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            return $code;
        }

        throw new RuntimeException('No free code was found.');
    }
}
