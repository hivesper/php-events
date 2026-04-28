<?php

namespace Test\Vesper\Tool\Event\_Fixtures;

use Vesper\Tool\Event\Infrastructure\InMemoryEventStore;

/**
 * In-memory event store that records every markProcessed() call. Used by
 * processor tests to verify the markProcessed lifecycle without standing up
 * a SQL database.
 */
class TrackingEventStore extends InMemoryEventStore
{
    /** @var list<string> */
    public array $markProcessedCalls = [];

    public function markProcessed(string $eventId): void
    {
        $this->markProcessedCalls[] = $eventId;
    }
}
