<?php

namespace Test\Vesper\Tool\Event\Unit\Redelivery;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;
use Vesper\Tool\Event\Infrastructure\Redelivery\InMemoryRedeliveryStore;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\Redelivery\Redelivery;
use Vesper\Tool\Event\Redelivery\RedeliveryStatus;

class InMemoryRedeliveryStoreTest extends TestCase
{
    private InMemoryRedeliveryStore $store;
    private RawEvent $event;

    protected function setUp(): void
    {
        $this->store = new InMemoryRedeliveryStore();
        $this->event = TestEventFactory::retrieveOrderPlaced();
    }

    public function test_next_returns_null_when_empty(): void
    {
        self::assertNull($this->store->next());
    }

    public function test_schedule_makes_redelivery_pickable_when_time_passes(): void
    {
        $now = CarbonImmutable::parse('2026-05-16 12:00:00');
        CarbonImmutable::setTestNow($now);

        try {
            $nextRetryAt = $now->subSecond();
            $this->store->schedule(Redelivery::schedule(
                event: $this->event,
                listener: 'App\\SomeListener',
                attemptNumber: 1,
                nextRetryAt: $nextRetryAt,
                lastError: new RuntimeException('boom'),
            ));

            $due = $this->store->next();

            $expected = Redelivery::retrieve(
                event: $this->event,
                listener: 'App\\SomeListener',
                status: RedeliveryStatus::Dispatching,
                attemptNumber: 1,
                nextRetryAt: $nextRetryAt,
                lastError: 'RuntimeException: boom',
                createdAt: $now,
                updatedAt: $now,
            );
            self::assertEquals($expected, $due);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_next_excludes_rows_whose_retry_time_is_in_the_future(): void
    {
        $this->store->schedule(Redelivery::schedule(
            event: $this->event,
            listener: 'App\\SomeListener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->addMinute(),
            lastError: new RuntimeException('boom'),
        ));

        self::assertNull($this->store->next());
    }

    public function test_next_returns_earliest_scheduled_first(): void
    {
        $eventEarly = TestEventFactory::retrieveOrderPlaced(['n' => 1]);
        $eventLate = TestEventFactory::retrieveOrderPlaced(['n' => 2]);

        $this->store->schedule(Redelivery::schedule(
            event: $eventLate,
            listener: 'App\\Late',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));
        $this->store->schedule(Redelivery::schedule(
            event: $eventEarly,
            listener: 'App\\Early',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subMinute(),
            lastError: new RuntimeException('boom'),
        ));

        $due = $this->store->next();

        self::assertNotNull($due);
        self::assertSame('App\\Early', $due->listener);
    }

    public function test_next_transitions_claimed_row_so_a_second_call_skips_it(): void
    {
        $this->store->schedule(Redelivery::schedule(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));

        $first = $this->store->next();
        $second = $this->store->next();

        self::assertNotNull($first);
        self::assertNull($second);
    }

    public function test_update_with_succeeded_finalises_a_claimed_row(): void
    {
        $this->store->schedule(Redelivery::schedule(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));
        $claimed = $this->store->next();
        self::assertNotNull($claimed);

        $this->store->update($claimed->markSucceeded());

        self::assertNull($this->store->next());
    }

    public function test_update_is_a_noop_when_row_is_not_in_dispatching(): void
    {
        $this->store->schedule(Redelivery::schedule(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));

        $stale = Redelivery::retrieve(
            event: $this->event,
            listener: 'App\\Listener',
            status: RedeliveryStatus::Succeeded,
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: 'RuntimeException: boom',
            createdAt: CarbonImmutable::now(),
            updatedAt: CarbonImmutable::now(),
        );
        $this->store->update($stale);

        self::assertNotNull($this->store->next());
    }

    public function test_update_with_failed_permanently_finalises_a_claimed_row(): void
    {
        $this->store->schedule(Redelivery::schedule(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 5,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));
        $claimed = $this->store->next();
        self::assertNotNull($claimed);

        $this->store->update($claimed->markFailedPermanently(new RuntimeException('final')));

        self::assertNull($this->store->next());
    }

    public function test_retry_now_re_queues_a_permanently_failed_row(): void
    {
        $this->store->schedule(Redelivery::schedule(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 5,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));
        $claimed = $this->store->next();
        self::assertNotNull($claimed);
        $this->store->update($claimed->markFailedPermanently(new RuntimeException('final')));

        $this->store->retryNow($this->event->id, 'App\\Listener');

        $due = $this->store->next();

        self::assertNotNull($due);
        self::assertSame('App\\Listener', $due->listener);
        self::assertSame(5, $due->attemptNumber);
    }

    public function test_schedule_is_a_noop_when_a_row_already_exists(): void
    {
        $this->store->schedule(Redelivery::schedule(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 2,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('first'),
        ));
        $this->store->schedule(Redelivery::schedule(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->addMinute(),
            lastError: new RuntimeException('replay-from-recovered-event'),
        ));

        $due = $this->store->next();

        self::assertNotNull($due);
        self::assertSame(2, $due->attemptNumber);
        self::assertStringContainsString('first', $due->lastError);
    }

    public function test_update_is_a_noop_for_unknown_row(): void
    {
        $orphan = Redelivery::schedule(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        )->claim()->markSucceeded();

        $this->store->update($orphan);

        self::assertNull($this->store->next());
    }

    public function test_retry_now_is_a_noop_for_unknown_row(): void
    {
        $this->store->retryNow('unknown-id', 'App\\Listener');

        self::assertNull($this->store->next());
    }
}
