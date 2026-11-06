<?php

namespace Test\Vesper\Tool\Event\Unit\Dispatch;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Vesper\Tool\Event\_Fixtures\IgnorableExceptionStub;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;
use Test\Vesper\Tool\Event\_Fixtures\TrackingListener;
use Vesper\Tool\Event\EventContext;
use Vesper\Tool\Event\EventContextFactory;
use Vesper\Tool\Event\EventHydrator;
use Vesper\Tool\Event\HandlerResolver;
use Vesper\Tool\Event\Infrastructure\Dispatch\DefaultListenerDispatcher;

class DefaultListenerDispatcherTest extends TestCase
{
    public function test_invokes_listener_with_hydrated_event(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced(['order_id' => 1]);
        $dispatcher = new DefaultListenerDispatcher();

        $received = null;
        $dispatcher->dispatch($event, function (object $e) use (&$received) {
            $received = $e;
        });

        self::assertEquals((object) ['order_id' => 1], $received);
    }

    public function test_rethrows_exception_from_listener(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $exception = new RuntimeException('boom');
        $dispatcher = new DefaultListenerDispatcher();

        $this->expectExceptionObject($exception);

        $dispatcher->dispatch($event, function () use ($exception) {
            throw $exception;
        });
    }

    public function test_swallows_exception_listed_in_ignored_exceptions(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $dispatcher = new DefaultListenerDispatcher(
            ignoredExceptions: [IgnorableExceptionStub::class],
        );

        $dispatcher->dispatch($event, function () {
            throw new IgnorableExceptionStub('expected');
        });

        self::assertTrue(true);
    }

    public function test_rethrows_exception_not_listed_in_ignored_exceptions(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $dispatcher = new DefaultListenerDispatcher(
            ignoredExceptions: [IgnorableExceptionStub::class],
        );

        $this->expectException(RuntimeException::class);

        $dispatcher->dispatch($event, function () {
            throw new RuntimeException('not ignorable');
        });
    }

    public function test_resolves_class_string_subscriber_via_handler_resolver(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced(['order_id' => 42]);
        $listener = new TrackingListener();

        $dispatcher = new DefaultListenerDispatcher(
            resolver: $this->resolverReturning($listener),
        );

        $dispatcher->dispatch($event, TrackingListener::class);

        self::assertEquals((object) ['order_id' => 42], $listener->received());
    }

    public function test_hydrates_payload_before_invoking_listener(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced(['order_id' => 7]);

        $typedEvent = new readonly class (7) {
            public function __construct(public int $orderId) {}
        };

        $received = null;
        $subscriber = function (object $e) use (&$received) {
            $received = $e;
        };

        $dispatcher = new DefaultListenerDispatcher(
            hydrator: $this->mockHydratorReturning(
                name: 'order.placed',
                payload: ['order_id' => 7],
                subscriber: $subscriber,
                event: $typedEvent,
            ),
        );

        $dispatcher->dispatch($event, $subscriber);

        self::assertSame($typedEvent, $received);
    }

    public function test_passes_the_event_context_to_a_listener_that_declares_a_second_parameter(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced(['order_id' => 1]);
        $dispatcher = new DefaultListenerDispatcher();

        $received = null;
        $dispatcher->dispatch($event, function (object $e, EventContext $context) use (&$received) {
            $received = $context;
        });

        self::assertEquals(
            new EventContext(eventId: $event->id, eventName: 'order.placed', createdAt: $event->createdAt),
            $received,
        );
    }

    public function test_calls_a_one_parameter_listener_with_the_event_only(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $dispatcher = new DefaultListenerDispatcher();

        $arguments = null;
        $dispatcher->dispatch($event, function (object $e) use (&$arguments) {
            $arguments = func_get_args();
        });

        self::assertEquals([(object) []], $arguments);
    }

    public function test_passes_the_context_built_by_the_configured_factory(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $appContext = new readonly class ('app') {
            public function __construct(public string $source) {}
        };

        $factory = $this->createMock(EventContextFactory::class);
        $factory->expects($this->once())
            ->method('create')
            ->with($event)
            ->willReturn($appContext);

        $dispatcher = new DefaultListenerDispatcher(contextFactory: $factory);

        $received = null;
        $dispatcher->dispatch($event, function (object $e, object $context) use (&$received) {
            $received = $context;
        });

        self::assertSame($appContext, $received);
    }

    public function test_keeps_the_same_context_when_the_event_is_dispatched_again(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $dispatcher = new DefaultListenerDispatcher();

        $received = [];
        $listener = function (object $e, EventContext $context) use (&$received) {
            $received[] = $context;
        };

        $dispatcher->dispatch($event, $listener);
        $dispatcher->dispatch($event, $listener);

        self::assertEquals($received[0], $received[1]);
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
        $hydrator->expects($this->once())
            ->method('hydrate')
            ->with($name, $payload, $subscriber)
            ->willReturn($event);

        return $hydrator;
    }
}
