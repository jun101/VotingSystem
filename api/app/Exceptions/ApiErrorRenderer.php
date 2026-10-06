<?php

namespace App\Exceptions;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDOException;
use Predis\CommunicationException;
use Predis\Connection\Resource\Exception\StreamInitException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The single place that turns an exception into an answer under `/api`.
 *
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

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        // A response built on purpose by the code that threw: keep it.
        if ($e instanceof HttpResponseException) {
            return null;
        }

        $this->useLanguageOf($request);

        if ($this->isDependencyFailure($e)) {
            return $this->error($request, 503, 'dependency_unavailable', headers: ['Retry-After' => '5']);
        }

        if ($e instanceof ValidationException) {
            return $this->error($request, 422, 'validation_failed', fields: $this->fieldsOf($e));
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

    /** The database or Redis could not be reached, at any point of the request. */
    private function isDependencyFailure(Throwable $e): bool
    {
        $detector = new LostConnectionDetector;

        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof CommunicationException
                || $cause instanceof StreamInitException
                || ($cause instanceof PDOException && $detector->causedByLostConnection($cause))
                || $detector->causedByLostConnection($cause)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Field name → codes of the rules that failed (`required`, `max`, ...).
     *
     * @return array<string, list<string>>
     */
    private function fieldsOf(ValidationException $e): array
    {
        $fields = [];

        foreach ($e->validator->failed() as $field => $rules) {
            $fields[(string) $field] = [];

            foreach (is_array($rules) ? array_keys($rules) : [] as $rule) {
                $fields[(string) $field][] = Str::snake((string) $rule);
            }
        }

        return $fields;
    }
}
