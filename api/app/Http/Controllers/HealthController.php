<?php

namespace App\Http\Controllers;

use App\Actions\System\CheckHealth;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * State of the API and of the services it depends on.
     *
     * Says whether the database and Redis answer, and returns the database server's clock.
     * Public. Limited to 60 requests per minute per IP address.
     *
     * @response array{data: array{status: 'ok', checks: array{database: 'ok', redis: 'ok'}, time: string}}
     */
    public function __invoke(CheckHealth $check): JsonResponse
    {
        return response()->json(['data' => $check()]);
    }
}
