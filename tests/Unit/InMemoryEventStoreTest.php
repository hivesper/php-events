<?php

namespace Test\Vesper\Tool\Event\Unit;

use PHPUnit\Framework\TestCase;
use Vesper\Tool\Event\Infrastructure\InMemoryEventStore;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;

class InMemoryEventStoreTest extends TestCase
{
    private InMemoryEventStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryEventStore();
    }

    public function test_next_returns_null_when_store_is_empty(): void
    {
        self::assertNull($this->store->next());
    }

    public function test_add_enqueues_an_event(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $this->store->add($event);

        self::assertSame($event, $this->store->next());
    }

    public function test_next_returns_events_in_fifo_order(): void
    {
        $first = TestEventFactory::retrieveOrderPlaced();
        $second = TestEventFactory::retrievePaymentReceived();

        $this->store->add($first);
        $this->store->add($second);

        self::assertSame($first, $this->store->next());
        self::assertSame($second, $this->store->next());
    }

    public function test_next_returns_null_after_all_events_are_consumed(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced());
        $this->store->next();

        self::assertNull($this->store->next());
    }

    public function test_multiple_events_can_be_enqueued_and_dequeued(): void
    {
        $events = [
            TestEventFactory::retrieve('a'),
            TestEventFactory::retrieve('b'),
            TestEventFactory::retrieve('c'),
        ];

        foreach ($events as $event) {
            $this->store->add($event);
        }

        foreach ($events as $expected) {
            self::assertSame($expected, $this->store->next());
        }

        self::assertNull($this->store->next());
    }
}
