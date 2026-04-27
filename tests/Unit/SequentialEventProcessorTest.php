<?php

namespace Test\Vesper\Tool\Event\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Vesper\Tool\Event\_Fixtures\IgnorableExceptionStub;
use Test\Vesper\Tool\Event\_Fixtures\RecordingSequentialEventProcessor;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;
use Test\Vesper\Tool\Event\_Fixtures\TrackingEventStore;
use Test\Vesper\Tool\Event\_Fixtures\TrackingListener;
use Vesper\Tool\Event\EventHydrator;
use Vesper\Tool\Event\EventSubscriberMap;
use Vesper\Tool\Event\HandlerResolver;
use Vesper\Tool\Event\Infrastructure\InMemoryEventStore;
use Vesper\Tool\Event\Infrastructure\InMemoryRedeliveryTracker;
use Vesper\Tool\Event\Infrastructure\SequentialEventProcessor;
use Vesper\Tool\Event\RedeliveryTracker;
use Vesper\Tool\Event\Retry\RetryPolicy;

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

    // ── retry policy / redelivery ──────────────────────────────────────────────

    public function test_calls_mark_processed_after_each_event_succeeds(): void
    {
        $store = new TrackingEventStore();
        $event = TestEventFactory::retrieveOrderPlaced();
        $store->add($event);

        $this->subscribers->subscribe('order.placed', function () {});

        $this->processor->process($store);

        self::assertSame([$event->id], $store->markProcessedCalls);
    }

    public function test_does_not_call_mark_processed_when_a_listener_throws_in_fail_fast_mode(): void
    {
        $store = new TrackingEventStore();
        $event = TestEventFactory::retrieveOrderPlaced();
        $store->add($event);

        $this->subscribers->subscribe('order.placed', function () {
            throw new RuntimeException('boom');
        });

        try {
            $this->processor->process($store);
            self::fail('Expected exception was not thrown');
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame([], $store->markProcessedCalls, 'event must remain in processing when dispatch propagates');
    }

    public function test_in_process_retry_succeeds_on_second_attempt(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromFormat('Y-m-d H:i:s.u', '2026-04-27 12:00:00.000000'));
        try {
            $event = TestEventFactory::retrieveOrderPlaced();
            $this->store->add($event);

            $calls = 0;
            $this->subscribers->subscribe('order.placed', function () use (&$calls) {
                $calls++;
                if ($calls === 1) {
                    throw new RuntimeException('transient');
                }
            });

            $policy = self::policyReturning(retryDelayMs: 50);
            $processor = new RecordingSequentialEventProcessor(
                $this->subscribers,
                retryPolicy: $policy,
                inProcessRetryThresholdMs: 100,
            );

            $processor->process($this->store);

            self::assertSame(2, $calls);
            self::assertSame([50], $processor->sleeps, 'one in-process sleep happened between the two attempts');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_in_process_retry_exhausted_propagates_in_fail_fast(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced());

        $exception = new RuntimeException('always fails');
        $this->subscribers->subscribe('order.placed', function () use ($exception) {
            throw $exception;
        });

        $policy = self::policyReturning(retryDelayMs: 10, exhaustAfter: 1);
        $processor = new RecordingSequentialEventProcessor(
            $this->subscribers,
            retryPolicy: $policy,
            inProcessRetryThresholdMs: 100,
        );

        $this->expectExceptionObject($exception);
        $processor->process($this->store);
    }

    public function test_persists_to_tracker_when_next_retry_delay_exceeds_threshold(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $this->store->add($event);

        $exception = new RuntimeException('boom');
        $this->subscribers->subscribe('order.placed', function () use ($exception) {
            throw $exception;
        });

        $tracker = $this->createMock(RedeliveryTracker::class);
        $tracker->expects($this->once())
            ->method('schedule')
            ->with(
                $this->callback(fn($e) => $e->id === $event->id),
                'Closure',
                1,
                $this->isInstanceOf(CarbonImmutable::class),
                $exception,
            );
        $tracker->method('nextDue')->willReturn(null);

        $policy = self::policyReturning(retryDelayMs: 5_000); // way above threshold
        $processor = new RecordingSequentialEventProcessor(
            $this->subscribers,
            retryPolicy: $policy,
            redeliveryTracker: $tracker,
            inProcessRetryThresholdMs: 100,
        );

        $processor->process($this->store);

        self::assertSame([], $processor->sleeps, 'no in-process sleep when delay exceeds threshold');
    }

    public function test_calls_mark_succeeded_when_listener_succeeds_with_tracker_configured(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $this->store->add($event);

        $this->subscribers->subscribe('order.placed', function () {});

        $tracker = $this->createMock(RedeliveryTracker::class);
        $tracker->expects($this->once())
            ->method('markSucceeded')
            ->with($event->id, 'Closure');
        $tracker->method('nextDue')->willReturn(null);

        $processor = new SequentialEventProcessor($this->subscribers, redeliveryTracker: $tracker);
        $processor->process($this->store);
    }

    public function test_ignored_exception_short_circuits_with_no_retry_no_propagation_no_tracker_calls(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced());

        $calls = 0;
        $this->subscribers->subscribe('order.placed', function () use (&$calls) {
            $calls++;
            throw new IgnorableExceptionStub('expected domain failure');
        });

        $tracker = $this->createMock(RedeliveryTracker::class);
        $tracker->expects($this->never())->method('schedule');
        $tracker->expects($this->never())->method('markFailedPermanently');
        $tracker->expects($this->never())->method('markSucceeded');
        $tracker->method('nextDue')->willReturn(null);

        $processor = new SequentialEventProcessor(
            $this->subscribers,
            retryPolicy: self::policyReturning(retryDelayMs: 10),
            redeliveryTracker: $tracker,
            ignoredExceptions: [IgnorableExceptionStub::class],
        );

        $processor->process($this->store);

        self::assertSame(1, $calls, 'listener ran exactly once and was not retried');
    }

    public function test_drains_due_redeliveries_after_the_main_queue(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();

        $tracker = new InMemoryRedeliveryTracker();
        $tracker->schedule(
            event: $event,
            listener: 'Closure',
            attemptNumber: 2,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('earlier failure'),
        );

        $received = null;
        $this->subscribers->subscribe('order.placed', function (object $e) use (&$received) {
            $received = $e;
        });

        $processor = new SequentialEventProcessor($this->subscribers, redeliveryTracker: $tracker);
        $processor->process($this->store);

        self::assertNotNull($received, 'redelivery dispatch invoked the listener');
        self::assertNull($tracker->nextDue(), 'redelivery row marked succeeded after dispatch');
    }

    public function test_calls_mark_failed_permanently_when_no_more_retries_with_tracker(): void
    {
        $this->store->add(TestEventFactory::retrieveOrderPlaced());

        $exception = new RuntimeException('boom');
        $this->subscribers->subscribe('order.placed', function () use ($exception) {
            throw $exception;
        });

        $tracker = $this->createMock(RedeliveryTracker::class);
        $tracker->expects($this->once())->method('markFailedPermanently');
        $tracker->expects($this->never())->method('schedule');
        $tracker->method('nextDue')->willReturn(null);

        $processor = new SequentialEventProcessor(
            $this->subscribers,
            redeliveryTracker: $tracker,
        );

        try {
            $processor->process($this->store);
            self::fail('expected exception');
        } catch (RuntimeException) {
            // expected — base class is fail-fast
        }
    }

    /**
     * Build a RetryPolicy stub that returns now()+$retryDelayMs for the first $exhaustAfter
     * attempts, then null.
     */
    private static function policyReturning(int $retryDelayMs, int $exhaustAfter = 5): RetryPolicy
    {
        return new readonly class ($retryDelayMs, $exhaustAfter) implements RetryPolicy {
            public function __construct(
                private int $retryDelayMs,
                private int $exhaustAfter,
            ) {}

            public function nextRetryAt(int $previousAttempt): ?CarbonImmutable
            {
                if ($previousAttempt > $this->exhaustAfter) {
                    return null;
                }
                return CarbonImmutable::now()->addMilliseconds($this->retryDelayMs);
            }
        };
    }
}
