<?php

namespace App\Actions\System;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use UnexpectedValueException;

/**
 * Asks the services the API depends on whether they answer (GET /api/v1/health).
 *
 * A service that does not answer makes the call throw its own connection exception;
 * the error renderer turns that into 503 `dependency_unavailable`. The connections have
 * short timeouts (config/database.php), so the answer is quick.
 */
final class CheckHealth
{
    /**
     * @return array{status: string, checks: array{database: string, redis: string}, time: string}
     */
    public function __invoke(): array
    {
        $now = DB::scalar('SELECT UTC_TIMESTAMP()');
        Redis::connection()->ping();

        if (! is_string($now)) {
            throw new UnexpectedValueException('The database did not return its time.');
        }

        return [
            'status' => 'ok',
            'checks' => ['database' => 'ok', 'redis' => 'ok'],
            'time' => Carbon::parse($now, 'UTC')->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
