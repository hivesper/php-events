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
     * attempt 2 run?" The processor classifies the returned timestamp as an
     * in-process retry (sleep then retry) or a persisted retry (insert into
     * the redelivery table) based on its own threshold.
     */
    public function nextRetryAt(int $previousAttempt): ?CarbonImmutable;
}
