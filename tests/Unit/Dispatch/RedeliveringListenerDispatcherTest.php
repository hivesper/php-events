<?php

namespace Test\Vesper\Tool\Event\Unit\Dispatch;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;
use Test\Vesper\Tool\Event\_Fixtures\ThrowingListener;
use Throwable;
use Vesper\Tool\Event\Dispatch\ListenerDispatcher;
use Vesper\Tool\Event\Infrastructure\Dispatch\RedeliveringListenerDispatcher;
use Vesper\Tool\Event\Infrastructure\Redelivery\InMemoryRedeliveryStore;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\Redelivery\Redelivery;
use Vesper\Tool\Event\Redelivery\RedeliveryStore;

class RedeliveringListenerDispatcherTest extends TestCase
{
    public function test_does_not_schedule_when_inner_returns_cleanly(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $store = $this->createMock(RedeliveryStore::class);
        $store->expects($this->never())->method('schedule');

        $dispatcher = new RedeliveringListenerDispatcher(self::passthroughDispatcher(), $store);

        $dispatcher->dispatch($event, function () {});
    }

    public function test_schedules_redelivery_with_attempt_one_when_inner_throws(): void
    {
        $now = CarbonImmutable::parse('2026-05-16 12:00:00');
        CarbonImmutable::setTestNow($now);

        try {
            $event = TestEventFactory::retrieveOrderPlaced();
            $exception = new RuntimeException('boom');

            $expected = Redelivery::schedule(
                event: $event,
                listener: 'Closure',
                attemptNumber: 1,
                nextRetryAt: $now,
                lastError: $exception,
            );

            $dispatcher = new RedeliveringListenerDispatcher(
                self::throwingDispatcher($exception),
                $this->mockStoreExpectingSchedule($expected),
            );

            $dispatcher->dispatch($event, function () {});
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_records_class_string_subscriber_under_its_class_name(): void
    {
        $now = CarbonImmutable::parse('2026-05-16 12:00:00');
        CarbonImmutable::setTestNow($now);

        try {
            $event = TestEventFactory::retrieveOrderPlaced();
            $exception = new RuntimeException('boom');

            $expected = Redelivery::schedule(
                event: $event,
                listener: ThrowingListener::class,
                attemptNumber: 1,
                nextRetryAt: $now,
                lastError: $exception,
            );

            $dispatcher = new RedeliveringListenerDispatcher(
                self::throwingDispatcher($exception),
                $this->mockStoreExpectingSchedule($expected),
            );

            $dispatcher->dispatch($event, ThrowingListener::class);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_swallows_inner_exception_after_scheduling(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();

        $dispatcher = new RedeliveringListenerDispatcher(
            self::throwingDispatcher(new RuntimeException('boom')),
            new InMemoryRedeliveryStore(),
        );

        $dispatcher->dispatch($event, function () {});

        self::assertTrue(true);
    }

    private function mockStoreExpectingSchedule(Redelivery $expected): RedeliveryStore
    {
        $store = $this->createMock(RedeliveryStore::class);
        $store->expects($this->once())
            ->method('schedule')
            ->with($expected);

        return $store;
    }

    private static function passthroughDispatcher(): ListenerDispatcher
    {
        return new readonly class implements ListenerDispatcher {
            public function dispatch(RawEvent $event, callable|string $subscriber): void {}
        };
    }

    private static function throwingDispatcher(Throwable $error): ListenerDispatcher
    {
        return new readonly class ($error) implements ListenerDispatcher {
            public function __construct(private Throwable $error) {}
            public function dispatch(RawEvent $event, callable|string $subscriber): void
            {
                throw $this->error;
            }
        };
    }
}
