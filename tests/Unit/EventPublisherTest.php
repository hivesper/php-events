<?php

namespace Test\Vesper\Tool\Event\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use stdClass;
use Test\Vesper\Tool\Event\_Fixtures\CapturingEventStore;
use Vesper\Tool\Event\EventPublisher;
use Vesper\Tool\Event\EventSerializer;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\RawEventStatus;
use Vesper\Tool\Event\SerializedEvent;

class EventPublisherTest extends TestCase
{
    public function test_publish_serializes_event_and_stores_it(): void
    {
        $now = CarbonImmutable::parse('2026-05-16 12:00:00');
        CarbonImmutable::setTestNow($now);

        try {
            $store = new CapturingEventStore();
            $publisher = new EventPublisher(
                $store,
                $this->serializerReturning(new SerializedEvent('order.placed', ['order_id' => 1])),
            );

            $id = $publisher->publish(new stdClass());

            $expected = RawEvent::retrieve(
                id: $id,
                name: 'order.placed',
                status: RawEventStatus::pending,
                payload: ['order_id' => 1],
                createdAt: $now,
                publishAt: $now,
            );
            self::assertEquals([$expected], $store->added);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_publish_returns_non_empty_string_id(): void
    {
        $publisher = new EventPublisher(
            new CapturingEventStore(),
            $this->serializerReturning(new SerializedEvent('order.placed', [])),
        );

        $id = $publisher->publish(new stdClass());

        self::assertNotEmpty($id);
    }

    private function serializerReturning(SerializedEvent $serialized): EventSerializer
    {
        $serializer = $this->createStub(EventSerializer::class);
        $serializer->method('serialize')->willReturn($serialized);

        return $serializer;
    }
}
