<?php

namespace Test\Tcds\Io\Ray\Unit;

use PHPUnit\Framework\TestCase;
use Tcds\Io\Ray\EventSubscriberMap;
use Tcds\Io\Ray\Infrastructure\InMemoryEventStore;
use Tcds\Io\Ray\Infrastructure\SequentialEventProcessor;
use Tcds\Io\Ray\RayEvent;
use Test\Tcds\Io\Ray\_Fixtures\TestEventFactory;

class SequentialEventProcessorTest extends TestCase
{
    private InMemoryEventStore $store;
    private EventSubscriberMap $subscribers;
    private SequentialEventProcessor $processor;

    protected function setUp(): void
    {
        $this->store = new InMemoryEventStore();
        $this->subscribers = new EventSubscriberMap();
        $this->processor = new SequentialEventProcessor($this->subscribers);
    }

    public function test_does_nothing_when_store_is_empty(): void
    {
        $called = false;
        $this->subscribers->subscribe('order.placed', function () use (&$called) {
            $called = true;
        });

        $this->processor->process($this->store);

        self::assertFalse($called);
    }

    public function test_dispatches_event_to_subscriber_matching_event_type(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $this->store->add($event);

        $received = null;
        $this->subscribers->subscribe('order.placed', function (RayEvent $e) use (&$received) {
            $received = $e;
        });

        $this->processor->process($this->store);

        self::assertSame($event, $received);
    }

    public function test_calls_all_subscribers_for_the_same_event_type(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced());

        $log = [];
        $this->subscribers->subscribe('order.placed', function () use (&$log) {
            $log[] = 'first';
        });
        $this->subscribers->subscribe('order.placed', function () use (&$log) {
            $log[] = 'second';
        });

        $this->processor->process($this->store);

        self::assertSame(['first', 'second'], $log);
    }

    public function test_routes_different_event_types_to_their_own_subscribers(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced(['order_id' => 1]));
        $this->store->add(TestEventFactory::retrievePaymentReceived(['amount' => 50]));

        $orderPayload = null;
        $paymentPayload = null;

        $this->subscribers->subscribe('order.placed', function (RayEvent $e) use (&$orderPayload) {
            $orderPayload = $e->payload;
        });
        $this->subscribers->subscribe('payment.received', function (RayEvent $e) use (&$paymentPayload) {
            $paymentPayload = $e->payload;
        });

        $this->processor->process($this->store);

        self::assertSame(['order_id' => 1], $orderPayload);
        self::assertSame(['amount' => 50], $paymentPayload);
    }

    public function test_processes_all_queued_events(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced(['n' => 1]));
        $this->store->add(TestEventFactory::retrieveOrderPlaced(['n' => 2]));
        $this->store->add(TestEventFactory::retrieveOrderPlaced(['n' => 3]));

        $received = [];
        $this->subscribers->subscribe('order.placed', function (RayEvent $e) use (&$received) {
            $received[] = $e->payload['n'];
        });

        $this->processor->process($this->store);

        self::assertSame([1, 2, 3], $received);
    }

    public function test_does_not_throw_when_event_has_no_subscribers(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced());

        $this->processor->process($this->store);

        self::assertTrue(true);
    }

    public function test_does_not_dispatch_to_subscriber_for_a_different_type(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced());

        $called = false;
        $this->subscribers->subscribe('payment.received', function () use (&$called) {
            $called = true;
        });

        $this->processor->process($this->store);

        self::assertFalse($called);
    }
}
