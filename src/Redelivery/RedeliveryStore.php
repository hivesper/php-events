<?php

namespace Vesper\Tool\Event\Redelivery;

interface RedeliveryStore
{
    /**
     * Insert-only on (event id, listener): if a row already exists for this pair, this call is a
     * no-op. The redelivery processor owns the row's state from that point on — re-scheduling
     * never resets attempt_number or other in-flight retry progress. To force an immediate retry
     * regardless of state, use retryNow() instead.
     */
    public function schedule(Redelivery $redelivery): void;

    /**
     * Claim the next due redelivery, atomically transitioning it from pending_retry to
     * dispatching so concurrent workers cannot pick up the same row. Returns null when
     * nothing is due. A worker that dies before calling update() leaves the row in
     * dispatching — call SqlRedeliveryStore::recoverStuckRedeliveries() from a separate
     * cron to recover it.
     */
    public function next(): ?Redelivery;

    /**
     * Write a claimed redelivery's current state back to storage. Guarded by status =
     * dispatching, so a sweeper that has already reset the row will not be overwritten —
     * the call becomes a no-op and the row is picked up again on a future tick.
     */
    public function update(Redelivery $redelivery): void;

    /**
     * Admin API: re-queue for immediate retry regardless of current status. Attempt count
     * is preserved, so the retry policy's max-attempts ceiling still applies.
     */
    public function retryNow(string $eventId, string $listener): void;
}
