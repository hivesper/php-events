<?php

namespace Vesper\Tool\Event;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Input to RedeliveryTracker::schedule() — the per-(event, listener) state captured
 * at the moment a dispatch failed and the retry policy chose to schedule another
 * attempt. Invariants are enforced in the constructor so callers can't quietly
 * persist a nonsensical row (attempt zero, empty listener identifier, etc.).
 */
readonly class RedeliveryRequest
{
    public function __construct(
        public RawEvent $event,
        public string $listener,
        public int $attemptNumber,
        public CarbonImmutable $nextRetryAt,
        public Throwable $lastError,
    ) {
        if ($attemptNumber < 1) {
            throw new InvalidArgumentException(
                "attemptNumber must be at least 1 (the attempt that just failed), got {$attemptNumber}",
            );
        }

        if (trim($listener) === '') {
            throw new InvalidArgumentException('listener must not be empty');
        }
    }
}
