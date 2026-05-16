<?php

namespace Test\Vesper\Tool\Event\_Fixtures;

use Override;
use Vesper\Tool\Event\EventStore;
use Vesper\Tool\Event\RawEvent;

class CapturingEventStore implements EventStore
{
    /** @var list<RawEvent> */
    public array $added = [];

    #[Override] public function add(RawEvent $event): void
    {
        $this->added[] = $event;
    }

    #[Override] public function next(): ?RawEvent
    {
        return null;
    }

    #[Override] public function markProcessed(RawEvent $event): void {}
}
