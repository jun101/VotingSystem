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
use App\Http\Controllers\Candidates\CandidateController;
use App\Http\Controllers\Candidates\CandidatePhotoController;
use App\Http\Controllers\Credentials\CredentialPreviewController;
use App\Http\Controllers\Credentials\CredentialRunController;
use App\Http\Controllers\Credentials\CredentialStateController;
use App\Http\Controllers\Credentials\GeneratedFileController;
use App\Http\Controllers\Credentials\VoterCredentialController;
use App\Http\Controllers\Elections\CoverController;
use App\Http\Controllers\Elections\ElectionController;
use App\Http\Controllers\Groups\GroupController;
use App\Http\Controllers\Groups\GroupMergeController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Institution\InstitutionController;
use App\Http\Controllers\Institution\LogoController;
use App\Http\Controllers\Parties\PartyController;
use App\Http\Controllers\Parties\PartyLogoController;
use App\Http\Controllers\Turnout\DashboardController;
use App\Http\Controllers\Turnout\TurnoutController;
use App\Http\Controllers\Users\InvitationController;
use App\Http\Controllers\Users\UserController;
use App\Http\Controllers\Vote\VoteBallotsController;
use App\Http\Controllers\Vote\VoteInfoController;
use App\Http\Controllers\Vote\VoteSessionController;
use App\Http\Controllers\Vote\VoteSubmitController;
use App\Http\Controllers\VoterImports\VoterImportController;
use App\Http\Controllers\Voters\VoterController;
use App\Http\Controllers\Voters\VoterCopyController;
use App\Http\Controllers\Voters\VoterExportController;
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

    // The voter's pages (slice 12). No institution user and no cookie session: the code is the credential,
    // and the `vote_session` cookie it opens is the only one. The election is found by its uuid, never
    // listed (a draft is a 404). Wrong codes are counted in the controller (failures only, never a valid
    // code), submissions per session there too; every voting route also shares one limiter per address and election.
    Route::prefix('vote/{election}')->middleware('voter')->group(function (): void {
        Route::get('/', VoteInfoController::class)->middleware('throttle:vote-public')->name('vote.info');
        // No `X-RateLimit-*` counters here: every wrong answer of this route is the same answer, headers included.
        Route::post('/session', [VoteSessionController::class, 'store'])->middleware('throttle.quiet:vote-public')->name('vote.session.store');
        Route::delete('/session', [VoteSessionController::class, 'destroy'])->middleware('throttle:vote-public')->name('vote.session.destroy');
        Route::get('/ballots', VoteBallotsController::class)->middleware('throttle:vote-public')->name('vote.ballots');
        Route::post('/submit', VoteSubmitController::class)->middleware('throttle:vote-public')->name('vote.submit');
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

    // The institution's home page (slice 14): 120 reads a minute per user, shared with the turnout.
    Route::get('/dashboard', DashboardController::class)->middleware(['cookie-session', 'auth', 'institution.active', 'throttle:turnout-read']);

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
        Route::get('/{election}/checklist', [ElectionController::class, 'checklist']);
        Route::post('/{election}/schedule', [ElectionController::class, 'schedule'])->middleware('throttle:election-schedule');
        Route::post('/{election}/unschedule', [ElectionController::class, 'unschedule'])->middleware('throttle:election-schedule');
        Route::post('/{election}/close', [ElectionController::class, 'close'])->middleware('throttle:election-close');
        Route::post('/{election}/extend', [ElectionController::class, 'extend'])->middleware('throttle:election-close');
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

        // The voters and their groups (slice 07a).
        Route::get('/{election}/voters', [VoterController::class, 'index']);
        Route::post('/{election}/voters', [VoterController::class, 'store'])->middleware('throttle:voters-write');
        Route::get('/{election}/groups', [GroupController::class, 'index']);
        Route::post('/{election}/groups', [GroupController::class, 'store'])->middleware('throttle:groups-write');

        // Turnout and the voters file (slice 14). The file is limited to 20 an hour per user.
        Route::get('/{election}/turnout', TurnoutController::class)->middleware('throttle:turnout-read');
        Route::get('/{election}/voters/export', VoterExportController::class)->middleware('throttle:voters-export');

        // Voters loaded from another election (slice 09b).
        Route::post('/{election}/voter-copies', VoterCopyController::class)->middleware('throttle:voter-copies');

        // The access codes of an election and their slip files (slice 10).
        Route::get('/{election}/credentials', CredentialStateController::class);
        Route::post('/{election}/credential-runs', CredentialRunController::class)->middleware('throttle:credential-runs');
        Route::post('/{election}/credential-previews', CredentialPreviewController::class)->middleware('throttle:credential-previews');
        Route::get('/{election}/generated-files', [GeneratedFileController::class, 'index']);

        // The voter import of an election (slice 08a): the template and the upload.
        Route::get('/{election}/voter-imports/template', [VoterImportController::class, 'template'])->middleware('throttle:voter-imports');
        Route::post('/{election}/voter-imports', [VoterImportController::class, 'store'])->middleware('throttle:voter-imports-upload');
    });

    // An import: its summary, its mapping, its rows (slice 08a). Confirm and the error file (08b).
    Route::middleware(['cookie-session', 'auth', 'institution.active'])->prefix('voter-imports')->group(function (): void {
        Route::get('/{import}', [VoterImportController::class, 'show'])->middleware('throttle:voter-imports');
        Route::patch('/{import}', [VoterImportController::class, 'update'])->middleware('throttle:voter-imports');
        Route::get('/{import}/rows', [VoterImportController::class, 'rows'])->middleware('throttle:voter-imports');
        Route::post('/{import}/confirm', [VoterImportController::class, 'confirm'])->middleware('throttle:voter-imports');
        Route::get('/{import}/errors', [VoterImportController::class, 'errors'])->middleware('throttle:voter-imports');
        Route::delete('/{import}', [VoterImportController::class, 'destroy'])->middleware('throttle:voter-imports');
    });

    Route::middleware(['cookie-session', 'auth', 'institution.active'])->prefix('ballots')->group(function (): void {
        Route::patch('/{ballot}', [BallotController::class, 'update'])->middleware('throttle:ballots-write');
        Route::delete('/{ballot}', [BallotController::class, 'destroy'])->middleware('throttle:ballots-write');

        // The candidates of a ballot (slice 06c). Without the `X-RateLimit-*` counters, so that the same
        // 422 for an unknown, another election's and another institution's party answers with the same headers.
        Route::post('/{ballot}/candidates', [CandidateController::class, 'store'])->middleware('throttle.quiet:candidates-write');
        Route::put('/{ballot}/candidates/order', [CandidateController::class, 'reorder'])->middleware('throttle.quiet:candidates-order');
    });

    Route::middleware(['cookie-session', 'auth', 'institution.active'])->prefix('candidates')->group(function (): void {
        Route::patch('/{candidate}', [CandidateController::class, 'update'])->middleware('throttle.quiet:candidates-write');
        Route::delete('/{candidate}', [CandidateController::class, 'destroy'])->middleware('throttle:candidates-write');
        Route::put('/{candidate}/photo', [CandidatePhotoController::class, 'update'])->middleware('throttle:candidate-photo');
        Route::delete('/{candidate}/photo', [CandidatePhotoController::class, 'destroy'])->middleware('throttle:candidates-write');
    });

    Route::middleware(['cookie-session', 'auth', 'institution.active'])->prefix('parties')->group(function (): void {
        Route::patch('/{party}', [PartyController::class, 'update'])->middleware('throttle:parties-write');
        Route::delete('/{party}', [PartyController::class, 'destroy'])->middleware('throttle:parties-write');
        Route::put('/{party}/logo', [PartyLogoController::class, 'update'])->middleware('throttle:party-logo');
        Route::delete('/{party}/logo', [PartyLogoController::class, 'destroy'])->middleware('throttle:parties-write');
    });

    Route::middleware(['cookie-session', 'auth', 'institution.active'])->prefix('voters')->group(function (): void {
        Route::patch('/{voter}', [VoterController::class, 'update'])->middleware('throttle:voters-write');
        Route::delete('/{voter}', [VoterController::class, 'destroy'])->middleware('throttle:voters-write');
        // A new code and a one-slip file for one voter (slice 10).
        Route::post('/{voter}/credential', VoterCredentialController::class)->middleware('throttle:credential-reissue');
    });

    // A file of slips: its status and the PDF (slice 10).
    Route::middleware(['cookie-session', 'auth', 'institution.active'])->prefix('generated-files')->group(function (): void {
        Route::get('/{file}', [GeneratedFileController::class, 'show']);
        Route::get('/{file}/download', [GeneratedFileController::class, 'download'])->middleware('throttle:file-download');
    });

    Route::middleware(['cookie-session', 'auth', 'institution.active'])->prefix('groups')->group(function (): void {
        Route::patch('/{group}', [GroupController::class, 'update'])->middleware('throttle:groups-write');
        Route::delete('/{group}', [GroupController::class, 'destroy'])->middleware('throttle:groups-write');
        // Without the `X-RateLimit-*` counters, so that the same 422 for an unknown, another election's and
        // another institution's group answers with the same headers (POST-groups-{group}-merge.md, scenario 5).
        Route::post('/{group}/merge', GroupMergeController::class)->middleware('throttle.quiet:groups-write');
    });
});
