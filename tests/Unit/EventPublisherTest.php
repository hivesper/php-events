<?php

namespace Test\Vesper\Tool\Event\Unit;

use PHPUnit\Framework\TestCase;
use stdClass;
use Vesper\Tool\Event\EventPublisher;
use Vesper\Tool\Event\EventSerializer;
use Vesper\Tool\Event\EventStore;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\SerializedEvent;

class EventPublisherTest extends TestCase
{
    public function test_publish_serializes_event_and_stores_it(): void
    {
        $domainEvent = new stdClass();

        $serializer = $this->createStub(EventSerializer::class);
        $serializer->method('serialize')->willReturn(new SerializedEvent('order.placed', ['order_id' => 1]));

        $store = $this->createMock(EventStore::class);
        $store->expects(self::once())->method('add')->with(
            $this->callback(
                fn(RawEvent $e) => $e->name === 'order.placed' && $e->payload === ['order_id' => 1],
            ),
        );

        new EventPublisher($store, $serializer)->publish($domainEvent);
    }

    public function test_publish_returns_non_empty_string_id(): void
    {
        $serializer = $this->createStub(EventSerializer::class);
        $serializer->method('serialize')->willReturn(new SerializedEvent('order.placed', []));

        $store = $this->createStub(EventStore::class);

        $id = new EventPublisher($store, $serializer)->publish(new stdClass());

        self::assertNotEmpty($id);
    }
}
