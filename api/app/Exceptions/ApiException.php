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
    /** @param  array<string, string>  $headers */
    public function __construct(int $status, public readonly string $errorCode, array $headers = [])
    {
        parent::__construct($status, $errorCode, null, $headers);
    }
}
