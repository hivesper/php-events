<?php

namespace Vesper\Tool\Event;

interface EventStore
{
    /** Call inside the caller's business transaction so event + business change commit together. */
    public function add(RawEvent $event): void;

    /**
     * Claim the next pending event, transitioning it from `pending` to `processing`. Returns null
     * when nothing is due. A worker that dies before calling markProcessed() leaves the row in
     * `processing` — call SqlEventStore::recoverStuckEvents() from a separate cron to recover it.
     */
    public function next(): ?RawEvent;

    public function markProcessed(string $eventId): void;
}
