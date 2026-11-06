<?php

namespace Vesper\Tool\Event\Infrastructure;

use Override;
use Vesper\Tool\Event\EventContext;
use Vesper\Tool\Event\EventContextFactory;
use Vesper\Tool\Event\RawEvent;

readonly class DefaultEventContextFactory implements EventContextFactory
{
    #[Override] public function create(RawEvent $event): EventContext
    {
        return new EventContext(
            eventId: $event->id,
            eventName: $event->name,
            createdAt: $event->createdAt,
        );
    }
}
