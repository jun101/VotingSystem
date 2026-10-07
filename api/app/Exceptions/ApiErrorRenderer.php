<?php

namespace App\Exceptions;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDOException;
use Predis\CommunicationException;
use Predis\Connection\Resource\Exception\StreamInitException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The single place that turns an exception into an answer.
 *
 * This application serves the API and nothing else (the web pages are another service
 * behind the proxy), so there is no path to except: a bare `/api`, a path the router
 * does not know and a request the framework refuses before routing all end here.
 * Every answer is JSON in the shape of docs/api/README.md section 3, whatever the debug
 * setting and the `Accept` header. A 500 and a 503 carry only the request id as
 * `reference`: no exception name, no file, no trace, no word about which service failed.
 */
final class ApiErrorRenderer
{
    /** Code of the error for the HTTP statuses of docs/api/README.md section 4. */
    private const CODES = [
        400 => 'malformed_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        410 => 'expired',
        413 => 'file_too_large',
        415 => 'file_type_not_allowed',
        419 => 'csrf_mismatch',
        422 => 'validation_failed',
        429 => 'too_many_attempts',
        503 => 'maintenance',
    ];

    /**
     * Laravel's name of a validation rule → the code of the API, where they differ
     * (docs/api/auth/POST-auth-register.md: `email: invalid`, `email: taken`). The others
     * keep the rule's name in snake case: `required`, `min`, `max`.
     */
    private const RULE_CODES = [
        'email' => 'invalid',
        'unique' => 'taken',
        'in' => 'invalid',
        'string' => 'invalid',
    ];

    /**
     * MariaDB client codes of "cannot connect", "server gone", "connection lost",
     * "server shutting down" and "statement ran past its time limit".
     */
    private const UNREACHABLE = [2002, 2003, 2005, 2006, 2013, 1053, 1927, 1969];

    /** The server answered and said no: wrong password (1045), no right on the database (1044). */
    private const REFUSED_BY_SERVER = [1044, 1045];

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        // A response built on purpose by the code that threw: keep it.
        if ($e instanceof HttpResponseException) {
            return null;
        }

        $this->useLanguageOf($request);

        // A request the framework refuses before routing, such as a malformed Host header.
        if ($this->causedBy($e, SuspiciousOperationException::class)) {
            return $this->error($request, 400, 'malformed_request');
        }

        if ($this->isDependencyFailure($e)) {
            return $this->error($request, 503, 'dependency_unavailable', headers: ['Retry-After' => '5']);
        }

        if ($e instanceof ValidationException) {
            return $this->error($request, 422, 'validation_failed', fields: $this->fieldsOf($e));
        }

        if ($e instanceof ApiException) {
            return $this->error($request, $e->getStatusCode(), $e->errorCode, headers: $e->getHeaders());
        }

        if ($e instanceof TokenMismatchException) {
            return $this->error($request, 419, 'csrf_mismatch');
        }

        if ($e instanceof AuthenticationException) {
            return $this->error($request, 401, 'unauthenticated');
        }

        if ($e instanceof HttpExceptionInterface && isset(self::CODES[$e->getStatusCode()])) {
            return $this->error($request, $e->getStatusCode(), self::CODES[$e->getStatusCode()], headers: $e->getHeaders());
        }

        return $this->error($request, 500, 'server_error');
    }

    /**
     * @param  array<string, list<string>>|null  $fields
     * @param  array<array-key, mixed>  $headers
     */
    private function error(Request $request, int $status, string $code, ?array $fields = null, array $headers = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => __('errors.'.$code)];

        if ($fields !== null) {
            $error['fields'] = $fields;
        }

        if ($status === 500 || $status === 503) {
            $error['reference'] = AssignRequestId::of($request);
        }

        return response()->json(['error' => $error], $status, $headers);
    }

    /** `Accept-Language` `fr` or `en`; French when absent or not one of them. */
    private function useLanguageOf(Request $request): void
    {
        /** @var list<string> $supported */
        $supported = config('app.supported_locales');

        app()->setLocale($request->getPreferredLanguage($supported) ?? $supported[0]);
    }

    /**
     * The database or Redis could not be reached, or did not answer in time, at any point
     * of the request. Only a failure raised by the database layer or by the Redis client
     * counts: the same words in any other exception, and a refused login ("Access denied":
     * a wrong password is our mistake, not an outage), stay a 500.
     */
    private function isDependencyFailure(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof CommunicationException || $cause instanceof StreamInitException) {
                return true;
            }

            if ($cause instanceof PDOException && $this->databaseUnreachable($cause)) {
                return true;
            }
        }

        return false;
    }

    private function databaseUnreachable(PDOException $e): bool
    {
        $code = $e instanceof QueryException ? ($e->errorInfo[1] ?? null) : $e->getCode();

        if (in_array($code, self::REFUSED_BY_SERVER, true)) {
            return false;
        }

        return in_array($code, self::UNREACHABLE, true)
            || (new LostConnectionDetector)->causedByLostConnection($e);
    }

    /** @param  class-string<Throwable>  $class */
    private function causedBy(Throwable $e, string $class): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Field name → codes of the rules that failed (`required`, `max`, ...). A field refused
     * by hand (`ValidationException::withMessages(['token' => ['invalid']])`) has no rule:
     * the messages given are its codes.
     *
     * @return array<string, list<string>>
     */
    private function fieldsOf(ValidationException $e): array
    {
        $failed = $e->validator->failed();
        $fields = [];

        foreach ($e->errors() as $field => $messages) {
            $rules = $failed[$field] ?? null;
            $codes = [];

            if (is_array($rules)) {
                foreach (array_keys($rules) as $rule) {
                    $codes[] = $this->codeOfRule($rule);
                }
            } elseif (is_array($messages)) {
                foreach ($messages as $message) {
                    $codes[] = is_string($message) ? $message : 'invalid';
                }
            }

            $fields[(string) $field] = array_values(array_unique($codes));
        }

        return $fields;
    }

    private function codeOfRule(string|int $rule): string
    {
        $code = Str::snake(class_basename((string) $rule));

        return self::RULE_CODES[$code] ?? $code;
    }
}
