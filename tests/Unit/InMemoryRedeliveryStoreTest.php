<?php

namespace Test\Vesper\Tool\Event\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;
use Vesper\Tool\Event\Infrastructure\InMemoryRedeliveryStore;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\RedeliveryRequest;

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
        $this->store->schedule(new RedeliveryRequest(
            event: $this->event,
            listener: 'App\\SomeListener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));

        $due = $this->store->next();

        self::assertNotNull($due);
        self::assertSame($this->event->id, $due->event->id);
        self::assertSame('App\\SomeListener', $due->listener);
        self::assertSame(1, $due->attemptNumber);
    }

    public function test_next_excludes_rows_whose_retry_time_is_in_the_future(): void
    {
        $this->store->schedule(new RedeliveryRequest(
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

        $this->store->schedule(new RedeliveryRequest(
            event: $eventLate,
            listener: 'App\\Late',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));
        $this->store->schedule(new RedeliveryRequest(
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
        $this->store->schedule(new RedeliveryRequest(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));

        $first = $this->store->next();
        $second = $this->store->next();

        self::assertNotNull($first);
        self::assertNull($second, 'a second call must skip the just-claimed row');
    }

    public function test_mark_succeeded_finalises_a_claimed_row(): void
    {
        $this->store->schedule(new RedeliveryRequest(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));
        $this->store->next(); // claim

        $this->store->markSucceeded($this->event->id, 'App\\Listener');

        self::assertNull($this->store->next());
    }

    public function test_mark_succeeded_is_a_noop_when_row_is_not_in_dispatching(): void
    {
        $this->store->schedule(new RedeliveryRequest(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));

        $this->store->markSucceeded($this->event->id, 'App\\Listener');

        $due = $this->store->next();
        self::assertNotNull($due, 'row remains claimable because markSucceeded did not apply');
    }

    public function test_mark_failed_permanently_finalises_a_claimed_row(): void
    {
        $this->store->schedule(new RedeliveryRequest(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 5,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));
        $this->store->next(); // claim

        $this->store->markFailedPermanently($this->event->id, 'App\\Listener', new RuntimeException('final'));

        self::assertNull($this->store->next());
    }

    public function test_retry_now_re_queues_a_permanently_failed_row(): void
    {
        $this->store->schedule(new RedeliveryRequest(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 5,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));
        $this->store->next(); // claim
        $this->store->markFailedPermanently($this->event->id, 'App\\Listener', new RuntimeException('final'));

        $this->store->retryNow($this->event->id, 'App\\Listener');

        $due = $this->store->next();

        self::assertNotNull($due);
        self::assertSame('App\\Listener', $due->listener);
        self::assertSame(5, $due->attemptNumber, 'attempt count is preserved across retryNow()');
    }

    public function test_schedule_is_idempotent_on_event_id_listener(): void
    {
        $this->store->schedule(new RedeliveryRequest(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->addMinute(),
            lastError: new RuntimeException('boom'),
        ));
        $this->store->schedule(new RedeliveryRequest(
            event: $this->event,
            listener: 'App\\Listener',
            attemptNumber: 2,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('again'),
        ));

        $due = $this->store->next();

        self::assertNotNull($due);
        self::assertSame(2, $due->attemptNumber, 'rescheduling updates attempt count, does not insert a duplicate');
    }

    public function test_mark_succeeded_is_a_noop_for_unknown_row(): void
    {
        $this->store->markSucceeded('unknown-id', 'App\\Listener');

        self::assertNull($this->store->next());
    }

    public function test_retry_now_is_a_noop_for_unknown_row(): void
    {
        $this->store->retryNow('unknown-id', 'App\\Listener');

        self::assertNull($this->store->next());
    }
}
