<?php

namespace Vesper\Tool\Event;

interface EventStore
{
    /**
     * Persist a new event in the outbox. Should be called inside the caller's
     * transaction so the event commits or rolls back together with the
     * business operation that produced it.
     */
    public function add(RawEvent $event): void;

    /**
     * Claim the next pending event for this worker, transitioning it from
     * `pending` to `processing`. Returns null when nothing is due.
     *
     * The returned event remains in `processing` state until the processor
     * calls markProcessed() once every listener has settled. A worker that
     * dies between next() and markProcessed() leaves the row in `processing`,
     * which is intentional — it lets a future stuck-events monitor recover it.
     */
    public function next(): ?RawEvent;

    /**
     * Advance the given event from `processing` to `processed`, signalling that
     * every listener has either succeeded, been persisted to the redelivery
     * queue, been swallowed by the ignored-exceptions list, or been marked
     * permanently failed.
     */
    public function markProcessed(string $eventId): void;
}
