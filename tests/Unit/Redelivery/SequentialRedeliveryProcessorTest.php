<?php

namespace Test\Vesper\Tool\Event\Unit\Redelivery;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;
use Throwable;
use Vesper\Tool\Event\Dispatch\ListenerDispatcher;
use Vesper\Tool\Event\EventSubscriberMap;
use Vesper\Tool\Event\Infrastructure\Dispatch\DefaultListenerDispatcher;
use Vesper\Tool\Event\Infrastructure\Redelivery\InMemoryRedeliveryStore;
use Vesper\Tool\Event\Infrastructure\Redelivery\SequentialRedeliveryProcessor;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\Redelivery\Redelivery;
use Vesper\Tool\Event\Redelivery\RedeliveryStatus;
use Vesper\Tool\Event\Redelivery\RedeliveryStore;
use Vesper\Tool\Event\Retry\RetryPolicy;

class SequentialRedeliveryProcessorTest extends TestCase
{
    private EventSubscriberMap $subscribers;

    protected function setUp(): void
    {
        $this->subscribers = new EventSubscriberMap();
    }

    public function test_does_nothing_when_store_is_empty(): void
    {
        $store = new InMemoryRedeliveryStore();
        $called = false;
        $this->subscribers->subscribe('order.placed', function () use (&$called) {
            $called = true;
        });

        $processor = new SequentialRedeliveryProcessor(
            $this->subscribers,
            new DefaultListenerDispatcher(),
            self::policyReturning(CarbonImmutable::now()->addMinute()),
        );

        $processor->process($store);

        self::assertFalse($called);
    }

    public function test_dispatches_due_redelivery_and_marks_succeeded(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $store = new InMemoryRedeliveryStore();
        $store->schedule(Redelivery::schedule(
            event: $event,
            listener: 'Closure',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('earlier failure'),
        ));

        $received = null;
        $this->subscribers->subscribe('order.placed', function (object $e) use (&$received) {
            $received = $e;
        });

        $processor = new SequentialRedeliveryProcessor(
            $this->subscribers,
            new DefaultListenerDispatcher(),
            self::policyReturning(CarbonImmutable::now()->addMinute()),
        );

        $processor->process($store);

        self::assertNotNull($received);
        self::assertNull($store->next());
    }

    public function test_marks_succeeded_when_dispatcher_returns_without_throwing(): void
    {
        $now = CarbonImmutable::parse('2026-05-16 12:00:00');
        CarbonImmutable::setTestNow($now);

        try {
            $event = TestEventFactory::retrieveOrderPlaced();
            $claimed = Redelivery::schedule(
                event: $event,
                listener: 'Closure',
                attemptNumber: 1,
                nextRetryAt: $now->subSecond(),
                lastError: new RuntimeException('initial'),
            )->claim();

            $expected = Redelivery::retrieve(
                event: $event,
                listener: 'Closure',
                status: RedeliveryStatus::Succeeded,
                attemptNumber: 1,
                nextRetryAt: $now->subSecond(),
                lastError: 'RuntimeException: initial',
                createdAt: $now,
                updatedAt: $now,
            );

            $this->subscribers->subscribe('order.placed', function () {});

            $processor = new SequentialRedeliveryProcessor(
                $this->subscribers,
                self::dispatcherThatDoesNotThrow(),
                self::policyReturning($now->addMinute()),
            );

            $processor->process($this->mockStoreExpectingUpdate($claimed, $expected));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_reschedules_with_attempt_plus_one_when_retry_policy_allows(): void
    {
        $now = CarbonImmutable::parse('2026-05-16 12:00:00');
        CarbonImmutable::setTestNow($now);

        try {
            $event = TestEventFactory::retrieveOrderPlaced();
            $nextRetryAt = $now->addMinute();
            $exception = new RuntimeException('still failing');

            $claimed = Redelivery::schedule(
                event: $event,
                listener: 'Closure',
                attemptNumber: 1,
                nextRetryAt: $now->subSecond(),
                lastError: new RuntimeException('previous failure'),
            )->claim();

            $expected = Redelivery::retrieve(
                event: $event,
                listener: 'Closure',
                status: RedeliveryStatus::PendingRetry,
                attemptNumber: 2,
                nextRetryAt: $nextRetryAt,
                lastError: 'RuntimeException: still failing',
                createdAt: $now,
                updatedAt: $now,
            );

            $this->subscribers->subscribe('order.placed', function () {});

            $processor = new SequentialRedeliveryProcessor(
                $this->subscribers,
                self::dispatcherThrowing($exception),
                self::policyReturning($nextRetryAt),
            );

            $processor->process($this->mockStoreExpectingUpdate($claimed, $expected));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_marks_failed_permanently_when_retry_policy_returns_null(): void
    {
        $now = CarbonImmutable::parse('2026-05-16 12:00:00');
        CarbonImmutable::setTestNow($now);

        try {
            $event = TestEventFactory::retrieveOrderPlaced();
            $exception = new RuntimeException('still failing');

            $claimed = Redelivery::schedule(
                event: $event,
                listener: 'Closure',
                attemptNumber: 3,
                nextRetryAt: $now->subSecond(),
                lastError: new RuntimeException('previous failure'),
            )->claim();

            $expected = Redelivery::retrieve(
                event: $event,
                listener: 'Closure',
                status: RedeliveryStatus::Failed,
                attemptNumber: 3,
                nextRetryAt: $now->subSecond(),
                lastError: 'RuntimeException: still failing',
                createdAt: $now,
                updatedAt: $now,
            );

            $this->subscribers->subscribe('order.placed', function () {});

            $processor = new SequentialRedeliveryProcessor(
                $this->subscribers,
                self::dispatcherThrowing($exception),
                self::policyReturning(null),
            );

            $processor->process($this->mockStoreExpectingUpdate($claimed, $expected));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_marks_failed_permanently_when_listener_is_no_longer_registered(): void
    {
        $now = CarbonImmutable::parse('2026-05-16 12:00:00');
        CarbonImmutable::setTestNow($now);

        try {
            $event = TestEventFactory::retrieveOrderPlaced();

            $claimed = Redelivery::schedule(
                event: $event,
                listener: 'App\\Removed',
                attemptNumber: 1,
                nextRetryAt: $now->subSecond(),
                lastError: new RuntimeException('previous failure'),
            )->claim();

            $expected = Redelivery::retrieve(
                event: $event,
                listener: 'App\\Removed',
                status: RedeliveryStatus::Failed,
                attemptNumber: 1,
                nextRetryAt: $now->subSecond(),
                lastError: "RuntimeException: Listener 'App\\Removed' is no longer registered for event 'order.placed'.",
                createdAt: $now,
                updatedAt: $now,
            );

            $processor = new SequentialRedeliveryProcessor(
                $this->subscribers,
                self::dispatcherThatDoesNotThrow(),
                self::policyReturning($now->addMinute()),
            );

            $processor->process($this->mockStoreExpectingUpdate($claimed, $expected));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_drains_multiple_due_rows_in_one_call(): void
    {
        $eventA = TestEventFactory::retrieveOrderPlaced(['n' => 1]);
        $eventB = TestEventFactory::retrieveOrderPlaced(['n' => 2]);

        $store = new InMemoryRedeliveryStore();
        $store->schedule(Redelivery::schedule(
            event: $eventA,
            listener: 'Closure',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('a failed'),
        ));
        $store->schedule(Redelivery::schedule(
            event: $eventB,
            listener: 'Closure',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('b failed'),
        ));

        $received = [];
        $this->subscribers->subscribe('order.placed', function (object $e) use (&$received) {
            $received[] = $e->n;
        });

        $processor = new SequentialRedeliveryProcessor(
            $this->subscribers,
            new DefaultListenerDispatcher(),
            self::policyReturning(CarbonImmutable::now()->addMinute()),
        );

        $processor->process($store);

        self::assertSame([1, 2], $received);
        self::assertNull($store->next());
    }

    private function mockStoreExpectingUpdate(Redelivery $claimed, Redelivery $expected): RedeliveryStore
    {
        $store = $this->createMock(RedeliveryStore::class);
        $store->method('next')->willReturnOnConsecutiveCalls($claimed, null);
        $store->expects($this->once())->method('update')->with($expected);
        $store->expects($this->never())->method('schedule');

        return $store;
    }

    private static function dispatcherThatDoesNotThrow(): ListenerDispatcher
    {
        return new readonly class implements ListenerDispatcher {
            public function dispatch(RawEvent $event, callable|string $subscriber): void {}
        };
    }

    private static function dispatcherThrowing(Throwable $error): ListenerDispatcher
    {
        return new readonly class ($error) implements ListenerDispatcher {
            public function __construct(private Throwable $error) {}
            public function dispatch(RawEvent $event, callable|string $subscriber): void
            {
                throw $this->error;
            }
        };
    }

    private static function policyReturning(?CarbonImmutable $nextRetryAt): RetryPolicy
    {
        return new readonly class ($nextRetryAt) implements RetryPolicy {
            public function __construct(private ?CarbonImmutable $nextRetryAt) {}
            public function nextRetryAt(int $previousAttempt): ?CarbonImmutable
            {
                return $this->nextRetryAt;
            }
        };
    }
}
