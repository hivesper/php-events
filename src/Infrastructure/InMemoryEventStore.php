<?php

namespace Vesper\Tool\Event\Infrastructure;

use Override;
use Vesper\Tool\Event\EventStore;
use Vesper\Tool\Event\RawEvent;

class InMemoryEventStore implements EventStore
{
    /** @var list<RawEvent> */
    private array $queue = [];

    #[Override] public function add(RawEvent $event): void
    {
        $this->queue[] = $event;
    }

    #[Override] public function next(): ?RawEvent
    {
        return array_shift($this->queue) ?? null;
    }

    #[Override] public function markProcessed(string $eventId): void
    {
        // No-op: the in-memory queue discards events on next(); there is no persisted status to flip.
    }
}
