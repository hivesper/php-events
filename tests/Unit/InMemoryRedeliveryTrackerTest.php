<?php

namespace Test\Vesper\Tool\Event\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;
use Vesper\Tool\Event\Infrastructure\InMemoryRedeliveryTracker;
use Vesper\Tool\Event\RawEvent;

class InMemoryRedeliveryTrackerTest extends TestCase
{
    private InMemoryRedeliveryTracker $tracker;
    private RawEvent $event;

    protected function setUp(): void
    {
        $this->tracker = new InMemoryRedeliveryTracker();
        $this->event = TestEventFactory::retrieveOrderPlaced();
    }

    public function test_next_due_returns_null_when_empty(): void
    {
        self::assertNull($this->tracker->nextDue());
    }

    public function test_schedule_makes_redelivery_pickable_when_time_passes(): void
    {
        $this->tracker->schedule(
            event: $this->event,
            listener: 'App\\SomeListener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        );

        $due = $this->tracker->nextDue();

        self::assertNotNull($due);
        self::assertSame($this->event->id, $due->event->id);
        self::assertSame('App\\SomeListener', $due->listener);
        self::assertSame(1, $due->attemptNumber);
    }

    public function test_next_due_excludes_rows_whose_retry_time_is_in_the_future(): void
    {
        $this->tracker->schedule(
            event: $this->event,
            listener: 'App\\SomeListener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->addMinute(),
            lastError: new RuntimeException('boom'),
        );

        self::assertNull($this->tracker->nextDue());
    }

    public function test_next_due_returns_earliest_scheduled_first(): void
    {
        $eventEarly = TestEventFactory::retrieveOrderPlaced(['n' => 1]);
        $eventLate = TestEventFactory::retrieveOrderPlaced(['n' => 2]);

        $this->tracker->schedule(
            event: $eventLate,
            listener: 'App\\Late',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        );
        $this->tracker->schedule(
            event: $eventEarly,
            listener: 'App\\Early',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subMinute(),
            lastError: new RuntimeException('boom'),
        );

        $due = $this->tracker->nextDue();

        self::assertNotNull($due);
        self::assertSame('App\\Early', $due->listener);
    }

    public function test_mark_succeeded_removes_row_from_due_queue(): void
    {
        $this->tracker->schedule(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        );

        $this->tracker->markSucceeded($this->event->id, 'App\\Listener');

        self::assertNull($this->tracker->nextDue());
    }

    public function test_mark_failed_permanently_removes_row_from_due_queue(): void
    {
        $this->tracker->schedule(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 5,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        );

        $this->tracker->markFailedPermanently($this->event->id, 'App\\Listener', new RuntimeException('final'));

        self::assertNull($this->tracker->nextDue());
    }

    public function test_retry_now_re_queues_a_permanently_failed_row(): void
    {
        $this->tracker->schedule(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 5,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        );
        $this->tracker->markFailedPermanently($this->event->id, 'App\\Listener', new RuntimeException('final'));

        $this->tracker->retryNow($this->event->id, 'App\\Listener');

        $due = $this->tracker->nextDue();

        self::assertNotNull($due);
        self::assertSame('App\\Listener', $due->listener);
        self::assertSame(5, $due->attemptNumber, 'attempt count is preserved across retryNow()');
    }

    public function test_schedule_is_idempotent_on_event_id_listener(): void
    {
        $this->tracker->schedule(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->addMinute(),
            lastError: new RuntimeException('boom'),
        );
        $this->tracker->schedule(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 2,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('again'),
        );

        $due = $this->tracker->nextDue();

        self::assertNotNull($due);
        self::assertSame(2, $due->attemptNumber, 'rescheduling updates attempt count, does not insert a duplicate');
    }

    public function test_mark_succeeded_is_a_noop_for_unknown_row(): void
    {
        $this->tracker->markSucceeded('unknown-id', 'App\\Listener');

        self::assertNull($this->tracker->nextDue());
    }

    public function test_retry_now_is_a_noop_for_unknown_row(): void
    {
        $this->tracker->retryNow('unknown-id', 'App\\Listener');

        self::assertNull($this->tracker->nextDue());
    }
}
