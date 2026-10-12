<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An error with a stable code of its own (docs/api/README.md section 3), such as
 * `invalid_credentials` or `already_verified`. ApiErrorRenderer answers it in the shared
 * shape; the message is `errors.<code>` in the language of the request.
 */
final class ApiException extends HttpException
{
    /**
     * @param  array<string, string>  $headers
     * @param  string|null  $messageKey  the `errors.<key>` of the message when it is not the code's own
     * @param  list<array<string, mixed>>|null  $items  what the error lists (the checklist's blockers or warnings)
     * @param  int|null  $count  a number the error reports (`email_count_changed`: the count now true)
     * @param  string|null  $phase  where an election is for a voter (`election_not_open`: `before`, `closed` or `paused`)
     * @param  list<string>|null  $ballots  the ballots (uuids) an error is about (`results_check_failed`)
     * @param  string|null  $reason  the text the platform admin gave (`institution_suspended` at sign-in only)
     */
    public function __construct(int $status, public readonly string $errorCode, array $headers = [], public readonly ?string $messageKey = null, public readonly ?array $items = null, public readonly ?int $count = null, public readonly ?string $phase = null, public readonly ?array $ballots = null, public readonly ?string $reason = null)
    {
        parent::__construct($status, $errorCode, null, $headers);
    }
}
