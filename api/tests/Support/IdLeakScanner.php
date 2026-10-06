<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Finds internal identifiers in what the server sends (SPEC NFR-SEC-08).
 *
 * Rules:
 *  - a key named "id" must hold a version 4 UUID;
 *  - a key ending in "_id" or "_ids" must not exist: a related record is named by a
 *    field carrying its UUID, or nested;
 *  - a Location header must not hold a path segment made only of digits.
 *
 * Part of the acceptance harness: it is not edited when a slice is coded.
 */
final class IdLeakScanner
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /**
     * @return list<string> one line per leak, empty when the data is clean
     */
    public static function findLeaks(mixed $data, string $path = '$'): array
    {
        if (! is_array($data)) {
            return [];
        }

        $leaks = [];

        foreach ($data as $key => $value) {
            $here = is_int($key) ? $path.'['.$key.']' : $path.'.'.$key;

            if (is_string($key)) {
                $name = strtolower($key);

                if ($name === 'id' && ! self::isUuid($value)) {
                    $leaks[] = $here.' is not a UUID: '.json_encode($value);
                } elseif (str_ends_with($name, '_id') || str_ends_with($name, '_ids')) {
                    $leaks[] = $here.' is a foreign-key style field';
                }
            }

            array_push($leaks, ...self::findLeaks($value, $here));
        }

        return $leaks;
    }

    /**
     * @return list<string>
     */
    public static function findLeaksInLocation(?string $location): array
    {
        if ($location === null || $location === '') {
            return [];
        }

        $path = (string) parse_url($location, PHP_URL_PATH);

        foreach (explode('/', $path) as $segment) {
            if ($segment !== '' && ctype_digit($segment)) {
                return ['Location header holds a numeric path segment: '.$location];
            }
        }

        return [];
    }

    public static function assertClean(TestResponse $response, string $label): void
    {
        $leaks = self::findLeaksInLocation($response->headers->get('Location'));

        $content = $response->getContent();
        $type = (string) $response->headers->get('Content-Type');

        if (is_string($content) && $content !== '' && str_contains($type, 'json')) {
            array_push($leaks, ...self::findLeaks(json_decode($content, true)));
        }

        Assert::assertSame(
            [],
            $leaks,
            "Internal identifier leaked by {$label} (SPEC NFR-SEC-08):\n".implode("\n", $leaks)
        );
    }

    private static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::UUID_V4, $value) === 1;
    }
}
