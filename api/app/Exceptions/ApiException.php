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
     */
    public function __construct(int $status, public readonly string $errorCode, array $headers = [], public readonly ?string $messageKey = null)
    {
        parent::__construct($status, $errorCode, null, $headers);
    }
}
