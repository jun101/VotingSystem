<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class CsrfController extends Controller
{
    /**
     * Start the session and set the CSRF cookie.
     *
     * Starts the browser's session if it has none and sets the `XSRF-TOKEN` cookie. The web
     * application calls it once before its first state-changing request, then copies the
     * cookie's value into the `X-XSRF-TOKEN` header of every request that changes something.
     * Public. Limited to 60 requests per minute per IP address.
     */
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
