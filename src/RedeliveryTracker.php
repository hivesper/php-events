<?php

namespace Vesper\Tool\Event;

use Throwable;

interface RedeliveryTracker
{
    /** Idempotent on (event id, listener): re-scheduling updates the existing row. */
    public function schedule(RedeliveryRequest $request): void;

    /**
     * Returns the next due redelivery, or null when none is due. Callers that intend to
     * dispatch the returned row should use processNextDue() instead — that holds the
     * row lock through the dispatch so concurrent workers cannot claim the same row.
     */
    public function nextDue(): ?DueRedelivery;

    /**
     * Atomically claim the next due redelivery and pass it to $handler. The handler must
     * not throw — if it does, the surrounding transaction rolls back and any
     * schedule / markSucceeded / markFailedPermanently side effects are lost. Wrap
     * dispatch in your own try/catch and defer the throw past this call if you need
     * fail-fast semantics.
     *
     * @param callable(DueRedelivery): void $handler
     */
    public function processNextDue(callable $handler): void;

    public function markFailedPermanently(string $eventId, string $listener, Throwable $lastError): void;

    public function markSucceeded(string $eventId, string $listener): void;

    /**
     * Admin API: re-queue for immediate retry regardless of current status. Attempt count
     * is preserved, so the retry policy's max-attempts ceiling still applies.
     */
    public function retryNow(string $eventId, string $listener): void;
}
