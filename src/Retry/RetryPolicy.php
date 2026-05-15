<?php

namespace Vesper\Tool\Event\Retry;

use Carbon\CarbonImmutable;

interface RetryPolicy
{
    /**
     * Return the absolute timestamp of the next retry attempt, or null when no
     * further retries should be made.
     *
     * $previousAttempt = 1 means "the first attempt just failed; when should
     * attempt 2 run?" The processor persists the returned timestamp in the
     * redelivery table; the cron-driven `processNextRedelivery()` flow picks
     * it up when it comes due.
     */
    public function nextRetryAt(int $previousAttempt): ?CarbonImmutable;
}
