<?php

namespace Vesper\Tool\Event;

use Throwable;

interface RedeliveryStore
{
    /** Idempotent on (event id, listener): re-scheduling updates the existing row. */
    public function schedule(RedeliveryRequest $request): void;

    /**
     * Claim the next due redelivery, atomically transitioning it from pending_retry to
     * dispatching so concurrent workers cannot pick up the same row. Returns null when
     * nothing is due. A worker that dies before finalising (markSucceeded /
     * markFailedPermanently / schedule) leaves the row in dispatching — call
     * SqlRedeliveryStore::recoverStuckRedeliveries() from a separate cron to recover it.
     */
    public function next(): ?DueRedelivery;

    /** No-op unless the row is currently in dispatching. */
    public function markFailedPermanently(string $eventId, string $listener, Throwable $lastError): void;

    /** No-op unless the row is currently in dispatching. */
    public function markSucceeded(string $eventId, string $listener): void;

    /**
     * Admin API: re-queue for immediate retry regardless of current status. Attempt count
     * is preserved, so the retry policy's max-attempts ceiling still applies.
     */
    public function retryNow(string $eventId, string $listener): void;
}
