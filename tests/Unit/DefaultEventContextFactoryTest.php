<?php

namespace Test\Vesper\Tool\Event\Unit;

use PHPUnit\Framework\TestCase;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;
use Vesper\Tool\Event\EventContext;
use Vesper\Tool\Event\Infrastructure\DefaultEventContextFactory;

class DefaultEventContextFactoryTest extends TestCase
{
    public function test_builds_the_context_from_the_stored_event(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();

        $context = new DefaultEventContextFactory()->create($event);

        self::assertEquals(
            new EventContext(eventId: $event->id, eventName: 'order.placed', createdAt: $event->createdAt),
            $context,
        );
    }
}
