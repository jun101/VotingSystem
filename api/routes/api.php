<?php

use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\Auth\CsrfController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResendVerificationController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Ballots\BallotController;
use App\Http\Controllers\Elections\CoverController;
use App\Http\Controllers\Elections\ElectionController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Institution\InstitutionController;
use App\Http\Controllers\Institution\LogoController;
use App\Http\Controllers\Parties\PartyController;
use App\Http\Controllers\Users\InvitationController;
use App\Http\Controllers\Users\UserController;
use Illuminate\Support\Facades\Route;

// The `/api` prefix is added by the framework; every route lives under `/api/v1`.
Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)->middleware('throttle:health');

    // Sign-up and sign-in (slice 02). The `cookie-session` group starts the session and
    // checks the CSRF token of every POST (bootstrap/app.php).
    Route::prefix('auth')->middleware('cookie-session')->group(function (): void {
        Route::get('/csrf', CsrfController::class)->middleware('throttle:auth-csrf');
        Route::post('/register', RegisterController::class)->middleware('throttle:auth-register');
        Route::post('/login', LoginController::class)->middleware('throttle:auth-login');
        Route::post('/logout', LogoutController::class)->middleware('auth');
        Route::get('/me', MeController::class)->middleware(['auth', 'institution.active']);
        Route::patch('/me', [MeController::class, 'update'])->middleware(['auth', 'institution.active']);
        Route::post('/verify-email', VerifyEmailController::class)->middleware('throttle:auth-verify-email');
        Route::post('/verify-email/resend', ResendVerificationController::class)
            ->middleware(['auth', 'institution.active', 'throttle:auth-resend']);
        Route::post('/forgot-password', ForgotPasswordController::class)->middleware('throttle:auth-forgot-password');
        Route::post('/reset-password', ResetPasswordController::class)->middleware('throttle:auth-reset-password');
        // Two-factor authentication (slice 04b). The challenge finishes a sign-in the password
        // step started in this session; the others are the signed-in user's own settings, with
        // one shared limiter.
        Route::post('/two-factor-challenge', TwoFactorChallengeController::class)->middleware('throttle:auth-two-factor-challenge');
        Route::middleware(['auth', 'institution.active'])->prefix('two-factor')->group(function (): void {
            Route::get('/', [TwoFactorController::class, 'show']);
            Route::post('/setup', [TwoFactorController::class, 'setup'])->middleware('throttle:two-factor');
            Route::post('/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:two-factor');
            Route::post('/disable', [TwoFactorController::class, 'disable'])->middleware('throttle:two-factor');
            Route::post('/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->middleware('throttle:two-factor');
        });
        Route::post('/accept-invitation', AcceptInvitationController::class)->middleware('throttle.quiet:auth-accept-invitation');
    });

    // The institution, its logo, its users and invitations (slice 04). Every route needs a
    // signed-in user of an active institution; `owner` keeps the writes, and the lists of
    // users and invitations, to the owners (a manager reads the profile only).
    Route::middleware(['cookie-session', 'auth', 'institution.active'])->group(function (): void {
        Route::get('/institution', [InstitutionController::class, 'show']);
        Route::patch('/institution', [InstitutionController::class, 'update'])->middleware('owner');
        Route::put('/institution/logo', [LogoController::class, 'update'])->middleware(['owner', 'throttle:logo-upload']);
        Route::delete('/institution/logo', [LogoController::class, 'destroy'])->middleware('owner');

        Route::middleware('owner')->group(function (): void {
            Route::get('/users', [UserController::class, 'index']);
            Route::delete('/users/{user}', [UserController::class, 'destroy']);
            Route::post('/users/{user}/two-factor/reset', [UserController::class, 'resetTwoFactor'])->middleware('throttle:two-factor');
            Route::get('/invitations', [InvitationController::class, 'index']);
            Route::post('/invitations', [InvitationController::class, 'store'])->middleware('throttle:invitations-create');
            Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy']);
        });
    });

    // The elections of the institution (slice 05). Owners and managers both pass, so there is no
    // role middleware; the record is bound (another institution's is a 404), then the rate limit
    // is counted.
    Route::middleware(['cookie-session', 'auth', 'institution.active'])->prefix('elections')->group(function (): void {
        Route::get('/', [ElectionController::class, 'index']);
        Route::post('/', [ElectionController::class, 'store'])->middleware('throttle:elections-write');
        Route::get('/{election}', [ElectionController::class, 'show']);
        Route::patch('/{election}', [ElectionController::class, 'update'])->middleware('throttle:elections-edit');
        Route::delete('/{election}', [ElectionController::class, 'destroy'])->middleware('throttle:elections-write');
        Route::post('/{election}/duplicate', [ElectionController::class, 'duplicate'])->middleware('throttle:elections-write');
        Route::put('/{election}/cover', [CoverController::class, 'update'])->middleware('throttle:election-cover');
        Route::delete('/{election}/cover', [CoverController::class, 'destroy']);

        // The ballots of an election (slice 06a).
        Route::get('/{election}/ballots', [BallotController::class, 'index']);
        Route::post('/{election}/ballots', [BallotController::class, 'store'])->middleware('throttle:ballots-write');
        // Without the `X-RateLimit-*` counters: another institution's ballot and an unknown one must
        // give the same answer, headers included (PUT-elections-{election}-ballots-order.md, scenario 4).
        Route::put('/{election}/ballots/order', [BallotController::class, 'reorder'])->middleware('throttle.quiet:ballots-order');

        // The parties of an election (slice 06b).
        Route::get('/{election}/parties', [PartyController::class, 'index']);
        Route::post('/{election}/parties', [PartyController::class, 'store'])->middleware('throttle:parties-write');
    });

    Route::middleware(['cookie-session', 'auth', 'institution.active'])->prefix('ballots')->group(function (): void {
        Route::patch('/{ballot}', [BallotController::class, 'update'])->middleware('throttle:ballots-write');
        Route::delete('/{ballot}', [BallotController::class, 'destroy'])->middleware('throttle:ballots-write');
    });

    Route::middleware(['cookie-session', 'auth', 'institution.active'])->prefix('parties')->group(function (): void {
        Route::patch('/{party}', [PartyController::class, 'update'])->middleware('throttle:parties-write');
        Route::delete('/{party}', [PartyController::class, 'destroy'])->middleware('throttle:parties-write');
    });
});
