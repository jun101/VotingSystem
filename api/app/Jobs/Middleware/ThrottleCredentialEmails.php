<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Keeps the access-code emails under `credentials.email_per_minute` a minute, for all workers
 * (the counter is in the cache). A job over the limit is released a little after the window
 * ends: it is not a failure and does not count as a try of the message. Nothing of the job is
 * read, so no code or address goes near it.
 */
final class ThrottleCredentialEmails
{
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

        // `hit` returns the count of this window, this call included.
        $count = RateLimiter::hit(self::KEY, self::WINDOW);

        if ($count > $limit) {
            // Spread the released jobs over the next window so they do not all come back at once.
            $wait = max(1, RateLimiter::availableIn(self::KEY)) + random_int(0, 10);

            // @phpstan-ignore-next-line the job is a queued job with InteractsWithQueue
            $job->release($wait);

            return;
        }

        $next($job);
    }
}
