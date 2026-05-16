<?php

namespace Test\Vesper\Tool\Event\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;
use Test\Vesper\Tool\Event\_Fixtures\TrackingEventStore;
use Test\Vesper\Tool\Event\_Fixtures\TrackingListener;
use Vesper\Tool\Event\EventHydrator;
use Vesper\Tool\Event\EventSubscriberMap;
use Vesper\Tool\Event\HandlerResolver;
use Vesper\Tool\Event\Infrastructure\Dispatch\DefaultListenerDispatcher;
use Vesper\Tool\Event\Infrastructure\InMemoryEventStore;
use Vesper\Tool\Event\Infrastructure\SequentialEventProcessor;

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

        self::assertEquals((object) ['order_id' => 1], $received);
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

        $orderEvent = null;
        $paymentEvent = null;

        $this->subscribers->subscribe('order.placed', function (object $e) use (&$orderEvent) {
            $orderEvent = $e;
        });
        $this->subscribers->subscribe('payment.received', function (object $e) use (&$paymentEvent) {
            $paymentEvent = $e;
        });

        $this->processor->process($this->store);

        self::assertEquals((object) ['order_id' => 1], $orderEvent);
        self::assertEquals((object) ['amount' => 50], $paymentEvent);
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

        $subscribers = new EventSubscriberMap(['order.placed' => [TrackingListener::class]]);
        $processor = new SequentialEventProcessor(
            $subscribers,
            new DefaultListenerDispatcher(resolver: $this->resolverReturning($listener)),
        );

        $processor->process($this->store);

        self::assertEquals((object) ['order_id' => 42], $listener->received());
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

        $subscribers = new EventSubscriberMap();
        $subscribers->subscribe('order.placed', $subscriber);

        $processor = new SequentialEventProcessor(
            $subscribers,
            new DefaultListenerDispatcher(hydrator: $this->mockHydratorReturning(
                name: 'order.placed',
                payload: ['order_id' => 7],
                subscriber: $subscriber,
                event: $typedEvent,
            )),
        );
        $processor->process($this->store);

        self::assertSame($typedEvent, $received);
    }

    public function test_calls_mark_processed_after_each_event_succeeds(): void
    {
        $store = new TrackingEventStore();
        $event = TestEventFactory::retrieveOrderPlaced();
        $store->add($event);

        $this->subscribers->subscribe('order.placed', function () {});

        $this->processor->process($store);

        self::assertSame([$event->id], $store->markProcessedCalls);
    }

    public function test_lets_listener_exceptions_propagate(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced());

        $this->subscribers->subscribe('order.placed', function () {
            throw new RuntimeException('boom');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->processor->process($this->store);
    }

    private function resolverReturning(callable|object $listener): HandlerResolver
    {
        $resolver = $this->createStub(HandlerResolver::class);
        $resolver->method('resolve')->willReturn($listener);

        return $resolver;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mockHydratorReturning(
        string $name,
        array $payload,
        callable|string $subscriber,
        object $event,
    ): EventHydrator {
        $hydrator = $this->createMock(EventHydrator::class);
        $hydrator->method('hydrate')
            ->with($name, $payload, $subscriber)
            ->willReturn($event);

        return $hydrator;
    }
}
