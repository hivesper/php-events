<?php

namespace Vesper\Tool\Event\Retry;

use Carbon\CarbonImmutable;

interface RetryPolicy
{
    /**
     * Return the absolute timestamp of the next retry, or null when no further retries should run.
     * $previousAttempt = 1 means "the first attempt just failed; when should attempt 2 run?"
     */
    public function nextRetryAt(int $previousAttempt): ?CarbonImmutable;
}
