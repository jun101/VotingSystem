<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    /**
     * Sign out.
     *
     * Ends the session: it is destroyed in Redis and the CSRF token regenerated, so the old
     * session cookie opens nothing. Signed-in user.
     */
    public function __invoke(Request $request): Response
    {
        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
