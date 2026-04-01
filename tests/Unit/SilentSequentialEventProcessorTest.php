<?php

namespace Test\Vesper\Tool\Event\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Vesper\Tool\Event\EventHydrator;
use Vesper\Tool\Event\EventSubscriberMap;
use Vesper\Tool\Event\Infrastructure\InMemoryEventStore;
use Vesper\Tool\Event\Infrastructure\SilentSequentialEventProcessor;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;
use Test\Vesper\Tool\Event\_Fixtures\ThrowingListener;

class SilentSequentialEventProcessorTest extends TestCase
{
    private InMemoryEventStore $store;
    private EventSubscriberMap $subscribers;
    private LoggerInterface $logger;
    private SilentSequentialEventProcessor $processor;

    protected function setUp(): void
    {
        $this->store = new InMemoryEventStore();
        $this->subscribers = new EventSubscriberMap();
        $this->logger = $this->createMock(LoggerInterface::class);

        $hydrator = $this->createStub(EventHydrator::class);
        $hydrator->method('hydrate')->willReturnCallback(fn(string $name, array $payload) => (object) $payload);

        $this->processor = new SilentSequentialEventProcessor(
            $this->subscribers,
            $this->logger,
            hydrator: $hydrator,
        );
    }

    public function test_continues_processing_remaining_subscribers_after_one_throws(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $this->store->add($event);

        $exception = new RuntimeException('Listener error');
        $secondCalled = false;
        $this->subscribers->subscribe('order.placed', function () use ($exception) {
            throw $exception;
        });
        $this->subscribers->subscribe('order.placed', function () use (&$secondCalled) {
            $secondCalled = true;
        });

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to dispatch event to listener.',
                [
                    'event'     => 'order.placed',
                    'event_id'  => $event->id,
                    'listener'  => 'Closure',
                    'exception' => $exception,
                ],
            );

        $this->processor->process($this->store);

        self::assertTrue($secondCalled);
    }

    public function test_logs_error_with_exception_context_on_failure(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $this->store->add($event);

        $exception = new RuntimeException('Boom');
        $this->subscribers->subscribe('order.placed', function () use ($exception) {
            throw $exception;
        });

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to dispatch event to listener.',
                [
                    'event'     => 'order.placed',
                    'event_id'  => $event->id,
                    'listener'  => 'Closure',
                    'exception' => $exception,
                ],
            );

        $this->processor->process($this->store);
    }

    public function test_logs_once_per_failing_listener(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced());

        $this->subscribers->subscribe('order.placed', function () {
            throw new RuntimeException('First');
        });
        $this->subscribers->subscribe('order.placed', function () {
            throw new RuntimeException('Second');
        });

        $this->logger->expects($this->exactly(2))->method('error');

        $this->processor->process($this->store);
    }

    public function test_does_not_log_when_no_listener_throws(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced());

        $this->subscribers->subscribe('order.placed', function () {});

        $this->logger->expects($this->never())->method('error');

        $this->processor->process($this->store);
    }

    public function test_logs_class_name_when_subscriber_is_a_class_string(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $this->store->add($event);

        $this->subscribers->subscribe('order.placed', ThrowingListener::class);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to dispatch event to listener.',
                [
                    'event'     => 'order.placed',
                    'event_id'  => $event->id,
                    'listener'  => ThrowingListener::class,
                    'exception' => new RuntimeException('ThrowingListener always fails.'),
                ]
            );

        $this->processor->process($this->store);
    }

    public function test_continues_processing_subsequent_events_after_failure(): void
    {
        $firstEvent = TestEventFactory::retrieveOrderPlaced();
        $this->store->add($firstEvent);
        $this->store->add(TestEventFactory::retrieveOrderPlaced(['n' => 2]));

        $exception = new RuntimeException('Missing n');
        $received = [];
        $this->subscribers->subscribe('order.placed', function (object $e) use (&$received, $exception) {
            if (!isset($e->n)) {
                throw $exception;
            }
            $received[] = $e->n;
        });

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to dispatch event to listener.',
                [
                    'event'     => 'order.placed',
                    'event_id'  => $firstEvent->id,
                    'listener'  => 'Closure',
                    'exception' => $exception,
                ],
            );

        $this->processor->process($this->store);

        self::assertSame([2], $received);
    }
}
