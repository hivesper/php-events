<?php

namespace Test\Vesper\Tool\Event\Unit;

use PHPUnit\Framework\TestCase;
use Vesper\Tool\Event\EventHydrator;
use Vesper\Tool\Event\EventSubscriberMap;
use Vesper\Tool\Event\HandlerResolver;
use Vesper\Tool\Event\Infrastructure\InMemoryEventStore;
use Vesper\Tool\Event\Infrastructure\SequentialEventProcessor;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;
use Test\Vesper\Tool\Event\_Fixtures\TrackingListener;

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

    public function test_dispatches_deserialized_payload_to_subscriber(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced(['order_id' => 1]));

        $received = null;
        $this->subscribers->subscribe('order.placed', function (object $e) use (&$received) {
            $received = $e;
        });

        $this->processor->process($this->store);

        self::assertSame(1, $received->order_id);
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

        $orderId = null;
        $amount = null;

        $this->subscribers->subscribe('order.placed', function (object $e) use (&$orderId) {
            $orderId = $e->order_id;
        });
        $this->subscribers->subscribe('payment.received', function (object $e) use (&$amount) {
            $amount = $e->amount;
        });

        $this->processor->process($this->store);

        self::assertSame(1, $orderId);
        self::assertSame(50, $amount);
    }

    public function test_processes_all_queued_events(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced(['n' => 1]));
        $this->store->add(TestEventFactory::retrieveOrderPlaced(['n' => 2]));
        $this->store->add(TestEventFactory::retrieveOrderPlaced(['n' => 3]));

        $received = [];
        $this->subscribers->subscribe('order.placed', function (object $e) use (&$received) {
            $received[] = $e->n;
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

    public function test_invokes_class_string_subscriber_via_invoke(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced(['order_id' => 42]));

        $listener = new TrackingListener();

        $resolver = $this->createStub(HandlerResolver::class);
        $resolver->method('resolve')->willReturn($listener);

        $subscribers = new EventSubscriberMap(['order.placed' => [TrackingListener::class]]);
        $processor = new SequentialEventProcessor($subscribers, $resolver);

        $processor->process($this->store);

        self::assertSame(42, $listener->received()->order_id);
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

    public function test_uses_hydrator_to_reconstruct_typed_domain_event(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced(['order_id' => 7]));

        $typedEvent = new readonly class (7) {
            public function __construct(public int $orderId) {}
        };

        $received = null;
        $subscriber = function (object $e) use (&$received) {
            $received = $e;
        };

        $hydrator = $this->createMock(EventHydrator::class);
        $hydrator->method('hydrate')
            ->with('order.placed', ['order_id' => 7], $subscriber)
            ->willReturn($typedEvent);

        $subscribers = new EventSubscriberMap();
        $subscribers->subscribe('order.placed', $subscriber);

        $processor = new SequentialEventProcessor($subscribers, hydrator: $hydrator);
        $processor->process($this->store);

        self::assertSame($typedEvent, $received);
    }

    public function test_hydrates_once_per_subscriber_passing_subscriber_as_context(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced(['order_id' => 1]));

        $subscriberA = function () {};
        $subscriberB = function () {};

        $hydrateCalls = [];
        $hydrator = $this->createMock(EventHydrator::class);
        $hydrator->expects($this->exactly(2))
            ->method('hydrate')
            ->willReturnCallback(function (string $name, array $payload, callable $subscriber) use (&$hydrateCalls): object {
                $hydrateCalls[] = $subscriber;
                return (object) $payload;
            });

        $subscribers = new EventSubscriberMap();
        $subscribers->subscribe('order.placed', $subscriberA);
        $subscribers->subscribe('order.placed', $subscriberB);

        $processor = new SequentialEventProcessor($subscribers, hydrator: $hydrator);
        $processor->process($this->store);

        self::assertCount(2, $hydrateCalls);
        self::assertSame($subscriberA, $hydrateCalls[0]);
        self::assertSame($subscriberB, $hydrateCalls[1]);
    }

}
