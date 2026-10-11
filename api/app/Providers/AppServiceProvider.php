<?php

namespace App\Providers;

use App\Auth\SessionUserProvider;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\GeneratedFile;
use App\Models\Party;
use App\Models\User;
use App\Models\Voter;
use App\Models\VoterGroup;
use App\Models\VoterImport;
use App\Policies\BallotPolicy;
use App\Policies\CandidatePolicy;
use App\Policies\ElectionPolicy;
use App\Policies\GeneratedFilePolicy;
use App\Policies\PartyPolicy;
use App\Policies\VoterGroupPolicy;
use App\Policies\VoterImportPolicy;
use App\Policies\VoterPolicy;
use App\Support\Credentials\SlipWriter;
use App\Support\Credentials\TcpdfSlipWriter;
use App\Support\Voting\ClientAddress;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The slip layout (slice 10): run 10b swaps this binding for the TCPDF writer.
        $this->app->bind(SlipWriter::class, TcpdfSlipWriter::class);

        // The OpenAPI file is exported by `make generate`; no documentation page is served.
        // Scramble is a development package: without it (an install made with
        // `--no-dev`) the application starts all the same.
        if (class_exists(Scramble::class)) {
            Scramble::ignoreDefaultRoutes();
        }
    }

    public function boot(): void
    {
        // Users are found by the guard without the institution scope (see SessionUserProvider).
        Auth::provider('institution-session', fn (Application $app, array $config) => new SessionUserProvider($app->make('hash'), Config::string('auth.providers.users.model')));

        Gate::policy(Election::class, ElectionPolicy::class);
        Gate::policy(Ballot::class, BallotPolicy::class);
        Gate::policy(Party::class, PartyPolicy::class);
        Gate::policy(Voter::class, VoterPolicy::class);
        Gate::policy(VoterGroup::class, VoterGroupPolicy::class);
        Gate::policy(VoterImport::class, VoterImportPolicy::class);
        Gate::policy(Candidate::class, CandidatePolicy::class);
        Gate::policy(GeneratedFile::class, GeneratedFilePolicy::class);

        $this->configureRateLimiters();
    }

    /**
     * Every limiter is declared here by name; a route uses it with `throttle:<name>`.
     * Later slices add theirs the same way.
     */
    private function configureRateLimiters(): void
    {
        RateLimiter::for('health', fn (Request $request) => Limit::perMinute(60)->by((string) $request->ip()));

        $this->configureAuthRateLimiters();
        $this->configureTeamRateLimiters();
        $this->configureElectionRateLimiters();
        $this->configureVoteRateLimiters();
        $this->configurePublicationRateLimiters();
    }

    /**
     * The public reads of the voting routes (docs/api/vote/): per address and election, high
     * because a class sits behind one address. The wrong-code and submit limits are counted by the
     * controllers (failures only, per session): see config/votes.php.
     */
    private function configureVoteRateLimiters(): void
    {
        RateLimiter::for('vote-public', function (Request $request) {
            $election = $request->route('election');

            return Limit::perMinute(Config::integer('votes.public_per_minute') * Config::integer('auth.rate_limit_factor'))
                ->by('vote-public:'.(is_string($election) ? strtolower($election) : '-').':'.ClientAddress::bucket((string) $request->ip()));
        });
    }

    /**
     * Publication (docs/api/publication/): publish, unpublish, archive and unarchive share 20 an hour per user,
     * and so does the admin report; the public pages are counted per address (and election): 120 a minute for
     * the results and the institution page, 30 a minute for the report.
     */
    private function configurePublicationRateLimiters(): void
    {
        $times = static fn (int $limit): int => $limit * Config::integer('auth.rate_limit_factor');
        $byUser = static function (Request $request): string {
            $user = $request->user();

            return $user instanceof User ? 'user:'.$user->uuid : 'ip:'.(string) $request->ip();
        };
        $byElection = static function (Request $request, string $name): string {
            $election = $request->route('election');

            return $name.':'.(is_string($election) ? strtolower($election) : '-').':'.ClientAddress::bucket((string) $request->ip());
        };

        RateLimiter::for('publication', fn (Request $request) => Limit::perHour($times(20))->by('publication:'.$byUser($request)));
        RateLimiter::for('report', fn (Request $request) => Limit::perHour($times(20))->by('report:'.$byUser($request)));
        RateLimiter::for('public-results', fn (Request $request) => Limit::perMinute($times(120))->by($byElection($request, 'public-results')));
        RateLimiter::for('public-report', fn (Request $request) => Limit::perMinute($times(30))->by($byElection($request, 'public-report')));
        RateLimiter::for('public-institution', fn (Request $request) => Limit::perMinute($times(120))->by('public-institution:'.ClientAddress::bucket((string) $request->ip())));
    }

    /**
     * The limits of docs/api/auth/, each multiplied by `auth.rate_limit_factor`
     * (AUTH_RATE_LIMIT_FACTOR; 1 when unset; raised in development and for the browser tests).
     * The counters live in the cache, which is Redis. An email address in a key is hashed.
     * The failed sign-in attempts per email and address are counted by AttemptLogin.
     */
    private function configureAuthRateLimiters(): void
    {
        $times = static fn (int $limit): int => $limit * Config::integer('auth.rate_limit_factor');
        $ip = static fn (Request $request): string => (string) $request->ip();

        RateLimiter::for('auth-csrf', fn (Request $request) => Limit::perMinute($times(60))->by($ip($request)));
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perHour($times(10))->by($ip($request)));
        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute($times(10))->by($ip($request)));
        // Slice 04b: 10 codes a minute from one address at the challenge, and 10 requests a minute
        // for one user at setup, confirm, disable and recovery codes (one counter for the four).
        RateLimiter::for('auth-two-factor-challenge', fn (Request $request) => Limit::perMinute($times(10))->by($ip($request)));
        RateLimiter::for('two-factor', function (Request $request) use ($times, $ip) {
            $user = $request->user();

            return Limit::perMinute($times(10))->by($user instanceof User ? 'user:'.$user->uuid : $ip($request));
        });
        RateLimiter::for('auth-verify-email', fn (Request $request) => Limit::perMinute($times(10))->by($ip($request)));
        RateLimiter::for('auth-reset-password', fn (Request $request) => Limit::perHour($times(10))->by($ip($request)));

        // 3 a minute for one user.
        RateLimiter::for('auth-resend', function (Request $request) use ($times, $ip) {
            $user = $request->user();

            return Limit::perMinute($times(3))->by($user instanceof User ? 'user:'.$user->uuid : $ip($request));
        });

        // 5 an hour for one address, and 3 an hour for one email address, whether it has an
        // account or not.
        RateLimiter::for('auth-forgot-password', function (Request $request) use ($times, $ip) {
            $limits = [Limit::perHour($times(5))->by('ip:'.$ip($request))];
            $email = $request->input('email');

            if (is_string($email) && $email !== '') {
                // By the account when there is one (the database ignores accents and case),
                // else by the text of the address. A hash either way.
                $normalized = mb_strtolower(trim($email));
                // The limiter runs before sign-in, so no institution can narrow the lookup: the
                // address is unique across all of them.
                $uuid = User::withoutInstitutionScope()->where('email', $normalized)->value('uuid');
                $limits[] = Limit::perHour($times(3))->by('email:'.hash('sha256', is_string($uuid) ? 'user:'.$uuid : 'email:'.$normalized));
            }

            return $limits;
        });
    }

    /**
     * Invitations and uploads (docs/api/users/POST-invitations.md, docs/api/institution/PUT-institution-logo.md),
     * counted per user, and the acceptance of an invitation, counted per address; multiplied by
     * the same factor as the auth limits.
     */
    private function configureTeamRateLimiters(): void
    {
        $times = static fn (int $limit): int => $limit * Config::integer('auth.rate_limit_factor');
        $byUser = static function (Request $request): string {
            $user = $request->user();

            return $user instanceof User ? 'user:'.$user->uuid : 'ip:'.(string) $request->ip();
        };

        RateLimiter::for('invitations-create', fn (Request $request) => Limit::perHour($times(20))->by($byUser($request)));
        RateLimiter::for('logo-upload', fn (Request $request) => Limit::perHour($times(10))->by($byUser($request)));
        RateLimiter::for('auth-accept-invitation', fn (Request $request) => Limit::perHour($times(10))->by((string) $request->ip()));
    }

    /**
     * The elections (docs/api/elections/): 60 an hour per user to create, delete and duplicate
     * (one shared counter), 120 an hour to edit, 20 an hour for a cover; multiplied by the same
     * factor as the others.
     */
    private function configureElectionRateLimiters(): void
    {
        $times = static fn (int $limit): int => $limit * Config::integer('auth.rate_limit_factor');
        $byUser = static function (Request $request): string {
            $user = $request->user();

            return $user instanceof User ? 'user:'.$user->uuid : 'ip:'.(string) $request->ip();
        };

        RateLimiter::for('elections-write', fn (Request $request) => Limit::perHour($times(60))->by($byUser($request)));
        RateLimiter::for('elections-edit', fn (Request $request) => Limit::perHour($times(120))->by($byUser($request)));
        // Scheduling and unscheduling share 120 an hour per user (docs/api/elections/POST-elections-{election}-schedule.md).
        RateLimiter::for('election-schedule', fn (Request $request) => Limit::perHour($times(120))->by($byUser($request)));
        // Closing early and extending share 20 an hour per user (docs/api/elections/POST-elections-{election}-close.md).
        RateLimiter::for('election-close', fn (Request $request) => Limit::perHour($times(20))->by($byUser($request)));
        RateLimiter::for('election-cover', fn (Request $request) => Limit::perHour($times(20))->by($byUser($request)));

        // The ballots (docs/api/ballots/): 120 an hour per user to add, change and delete, 240 to put in order.
        RateLimiter::for('ballots-write', fn (Request $request) => Limit::perHour($times(120))->by($byUser($request)));
        RateLimiter::for('ballots-order', fn (Request $request) => Limit::perHour($times(240))->by($byUser($request)));

        // The parties (docs/api/parties/): 120 an hour per user to add, change and delete.
        RateLimiter::for('parties-write', fn (Request $request) => Limit::perHour($times(120))->by($byUser($request)));
        // The voters (docs/api/voters/): 240 an hour per user to add, change and delete; the groups
        // (docs/api/groups/): 120 an hour per user to add, rename, merge and delete.
        RateLimiter::for('voters-write', fn (Request $request) => Limit::perHour($times(240))->by($byUser($request)));
        RateLimiter::for('groups-write', fn (Request $request) => Limit::perHour($times(120))->by($byUser($request)));
        // Voters loaded from another election (docs/api/voters/): 20 an hour per user.
        RateLimiter::for('voter-copies', fn (Request $request) => Limit::perHour($times(20))->by($byUser($request)));
        // The candidates (docs/api/candidates/): 120 an hour per user to add, change and delete, 240 to put in order.
        RateLimiter::for('candidates-write', fn (Request $request) => Limit::perHour($times(120))->by($byUser($request)));
        RateLimiter::for('candidates-order', fn (Request $request) => Limit::perHour($times(240))->by($byUser($request)));

        // The voter import (docs/api/voter-imports/): 20 uploads an hour per user; every other import
        // route (template, summary, mapping, rows, confirm, errors, cancel) shares 240 an hour.
        RateLimiter::for('voter-imports-upload', fn (Request $request) => Limit::perHour($times(20))->by($byUser($request)));
        RateLimiter::for('voter-imports', fn (Request $request) => Limit::perHour($times(240))->by($byUser($request)));

        // The access codes (docs/api/credentials/): 20 runs an hour per user, 120 previews, 60 reissues, 120 downloads.
        RateLimiter::for('credential-runs', fn (Request $request) => Limit::perHour($times(20))->by($byUser($request)));
        RateLimiter::for('credential-previews', fn (Request $request) => Limit::perHour($times(120))->by($byUser($request)));
        RateLimiter::for('credential-reissue', fn (Request $request) => Limit::perHour($times(60))->by($byUser($request)));
        RateLimiter::for('file-download', fn (Request $request) => Limit::perHour($times(120))->by($byUser($request)));

        // The turnout and the dashboard (docs/api/turnout/): 120 reads a minute per user, one shared counter.
        RateLimiter::for('turnout-read', fn (Request $request) => Limit::perMinute($times(120))->by($byUser($request)));

        // The voters file (docs/api/turnout/GET-elections-{election}-voters-export.md): 20 an hour per user.
        RateLimiter::for('voters-export', fn (Request $request) => Limit::perHour($times(20))->by($byUser($request)));

        // The audit log file (docs/api/audit/GET-audit-export.md): 20 an hour per user.
        RateLimiter::for('audit-export', fn (Request $request) => Limit::perHour($times(20))->by($byUser($request)));

        // The logo of a party: 20 an hour per user, as a cover (the removal counts with the writes).
        RateLimiter::for('party-logo', fn (Request $request) => Limit::perHour($times(20))->by($byUser($request)));

        // The photo of a candidate: 20 an hour per user (the removal counts with the candidate writes).
        RateLimiter::for('candidate-photo', fn (Request $request) => Limit::perHour($times(20))->by($byUser($request)));

        // The results (docs/api/results/): the note 60 an hour, the second round 20 an hour, per user.
        RateLimiter::for('result-note', fn (Request $request) => Limit::perHour($times(60))->by($byUser($request)));
        RateLimiter::for('second-round', fn (Request $request) => Limit::perHour($times(20))->by($byUser($request)));
    }
}
