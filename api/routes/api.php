<?php

use App\Http\Controllers\Auth\CsrfController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResendVerificationController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\HealthController;
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
    });
});
