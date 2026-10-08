<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shorthands for the slice 04 tests: an institution with an owner and a manager, a second
 * one, a signed-in browser, and the stored logo files.
 *
 * Part of the acceptance harness: it is not edited when a slice is coded.
 */
final class Team
{
    /**
     * Two institutions: `a` with an owner, a second owner and a manager; `b` with an owner and
     * a manager. Returns the accounts as `Accounts::user()` does.
     *
     * @return array{a: array{owner: array, owner2: array, manager: array}, b: array{owner: array, manager: array}}
     */
    public static function two(): array
    {
        $aOwner = Accounts::user(['name' => 'Alice Owner A', 'email' => 'alice.a@example.test']);
        $bOwner = Accounts::user(['name' => 'Bruno Owner B', 'email' => 'bruno.b@example.test']);

        return [
            'a' => [
                'owner' => $aOwner,
                'owner2' => Accounts::user(['role' => 'owner', 'name' => 'Aline Second A', 'email' => 'aline.a@example.test', 'institution' => $aOwner['institution']]),
                'manager' => Accounts::user(['role' => 'manager', 'name' => 'Armand Manager A', 'email' => 'armand.a@example.test', 'institution' => $aOwner['institution']]),
            ],
            'b' => [
                'owner' => $bOwner,
                'manager' => Accounts::user(['role' => 'manager', 'name' => 'Berthe Manager B', 'email' => 'berthe.b@example.test', 'institution' => $bOwner['institution']]),
            ],
        ];
    }

    /** Signs the browser in as this account and checks it worked. */
    public static function signIn(TestCase $test, array $account): void
    {
        $test->browser->login($account['email'], $account['password'])->assertOk();
    }

    /** The parts of an answer that must be identical for "not yours" and "does not exist". */
    public static function shape(TestResponse $response): array
    {
        $headers = $response->headers->allPreserveCase();
        foreach (['X-Request-Id', 'Date', 'Set-Cookie', 'Content-Length', 'Age'] as $volatile) {
            unset($headers[$volatile], $headers[strtolower($volatile)]);
        }
        ksort($headers);

        return ['status' => $response->getStatusCode(), 'body' => $response->getContent(), 'headers' => $headers];
    }

    /** Empties the `media` disk, where the logos are stored. */
    public static function clearMedia(): void
    {
        $disk = Storage::disk('media');
        foreach ($disk->allFiles() as $file) {
            $disk->delete($file);
        }
    }

    /** The names of the files on the `media` disk, sorted. */
    public static function mediaFiles(): array
    {
        $files = Storage::disk('media')->allFiles();
        sort($files);

        return $files;
    }
}
