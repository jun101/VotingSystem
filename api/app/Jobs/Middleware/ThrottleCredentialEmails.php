<?php

namespace App\Jobs\Middleware;

use App\Models\Voter;
use Closure;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Keeps the access-code emails under `credentials.email_per_minute` a minute for each institution,
 * and under `credentials.email_platform_per_minute` for all of them together, for all workers
 * (the counters are in the cache). A job over a limit is released a little after the window
 * ends: it is not a failure and does not count as a try of the message. The institution is found
 * from the voter's UUID the job exposes; no code or address goes near it.
 */
final class ThrottleCredentialEmails
{
    /** The key of the platform cap; a job with no known institution is counted under the bare key. */
    private const KEY = 'credential-emails';

    /** The length of the counting window, in seconds. */
    private const WINDOW = 60;

    public function handle(object $job, Closure $next): void
    {
        // The synchronous driver cannot release a job: it would be lost. It is the test driver.
        $queued = property_exists($job, 'job') ? $job->job : null;

        if ($queued instanceof SyncJob) {
            $next($job);

            return;
        }

        $limit = max(1, Config::integer('credentials.email_per_minute', 120));
        $cap = max(1, Config::integer('credentials.email_platform_per_minute', 600));
        $institution = $this->institutionKey($job);

        // One institution's burst waits on its own counter and leaves the others' messages alone.
        $own = $institution === null ? self::KEY : self::KEY.':institution:'.$institution;

        // `hit` returns the count of this window, this call included.
        if (RateLimiter::hit($own, self::WINDOW) > $limit) {
            $this->wait($job, $own);

            return;
        }

        if (RateLimiter::hit(self::KEY.':platform', self::WINDOW) > $cap) {
            $this->wait($job, self::KEY.':platform');

            return;
        }

        $next($job);
    }

    /** Spreads the released jobs over the next window so they do not all come back at once. */
    private function wait(object $job, string $key): void
    {
        $wait = max(1, RateLimiter::availableIn($key)) + random_int(0, 10);

        // @phpstan-ignore-next-line the job is a queued job with InteractsWithQueue
        $job->release($wait);
    }

    /** The institution's key from the voter's UUID, or null when the job cannot tell. */
    private function institutionKey(object $job): ?int
    {
        if (! method_exists($job, 'voterUuid')) {
            return null;
        }

        $uuid = $job->voterUuid();

        // Queue job: nobody is signed in, so the scope reads no institution; the voter's UUID is the key.
        $institution = is_string($uuid) ? Voter::withoutInstitutionScope()->where('uuid', $uuid)->value('institution_id') : null;

        return is_int($institution) ? $institution : null;
    }
}
