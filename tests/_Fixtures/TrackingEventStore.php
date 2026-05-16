<?php

namespace Test\Vesper\Tool\Event\_Fixtures;

use Vesper\Tool\Event\Infrastructure\InMemoryEventStore;
use Vesper\Tool\Event\RawEvent;

class TrackingEventStore extends InMemoryEventStore
{
    /** @var list<string> */
    public array $markProcessedCalls = [];

    public function markProcessed(RawEvent $event): void
    {
        $this->markProcessedCalls[] = $event->id;
    }
}
