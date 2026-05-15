<?php

namespace Vesper\Tool\Event;

use Carbon\CarbonImmutable;
use Throwable;

interface RedeliveryTracker
{
    /**
     * Persist a failed dispatch so it can be retried later.
     *
     * Idempotent on (event id, listener): scheduling again updates the existing
     * row's attempt_number, next_retry_at, and last_error. The full RawEvent is
     * passed (not just the id) so in-memory implementations can keep their own
     * copy for later rehydration; SQL implementations only need event->id and
     * join event_outbox to look the rest up.
     */
    public function schedule(
        RawEvent $event,
        string $listener,
        int $attemptNumber,
        CarbonImmutable $nextRetryAt,
        Throwable $lastError,
    ): void;

    /**
     * Pick up the next due redelivery (worker-safe; locks the row on MySQL).
     * Returns null when none are due.
     *
     * Note: callers that intend to dispatch the returned redelivery should use
     * processNextDue() instead — that wraps the read and the subsequent state
     * transition in a single transaction, so the row lock is held through the
     * dispatch and concurrent workers cannot pick up the same row.
     */
    public function nextDue(): ?DueRedelivery;

    /**
     * Atomically claim the next due redelivery and pass it to $handler. SQL
     * implementations open a transaction so the row lock acquired by nextDue()
     * is held through the handler call, preventing concurrent workers from
     * picking up the same row.
     *
     * The handler is expected to drive dispatch and the resulting state
     * transition (schedule / markSucceeded / markFailedPermanently). It must
     * not throw — wrap dispatch in your own try/catch if you need to swallow
     * or defer the exception, otherwise the transaction will roll back and
     * those state transitions will be lost.
     *
     * @param callable(DueRedelivery): void $handler
     */
    public function processNextDue(callable $handler): void;

    /**
     * Mark a redelivery as permanently failed — no further automatic attempts.
     * The row stays in the table so operators can inspect it and call retryNow()
     * if appropriate.
     */
    public function markFailedPermanently(string $eventId, string $listener, Throwable $lastError): void;

    /**
     * Mark a redelivery as succeeded. The row stays in the table for audit;
     * future nextDue() calls will not return it.
     */
    public function markSucceeded(string $eventId, string $listener): void;

    /**
     * Application-side admin API: re-queue a redelivery for immediate retry.
     * Sets status to pending_retry and next_retry_at to now(), regardless of the
     * row's current status (including 'failed'). Attempt count is preserved so
     * the retry policy's max-attempts ceiling still applies on subsequent
     * automatic failures.
     */
    public function retryNow(string $eventId, string $listener): void;
}
