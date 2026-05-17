<?php

namespace Test\Vesper\Tool\Event\_Fixtures;

use Carbon\CarbonInterval;
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

    #[Override] public function recoverStuckEvents(CarbonInterval $olderThan): int
    {
        return 0;
    }
}
