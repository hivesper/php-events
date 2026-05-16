<?php

namespace Test\Vesper\Tool\Event\Unit\Redelivery;

use Carbon\CarbonImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\Redelivery\Redelivery;
use Vesper\Tool\Event\Redelivery\RedeliveryStatus;

class RedeliveryTest extends TestCase
{
    public function test_schedule_constructs_a_pending_row_with_supplied_values(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $nextRetryAt = CarbonImmutable::now()->addMinute();
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);

        try {
            $redelivery = Redelivery::schedule(
                event: $event,
                listener: 'App\\Listener',
                attemptNumber: 1,
                nextRetryAt: $nextRetryAt,
                lastError: new RuntimeException('boom'),
            );

            $expected = Redelivery::retrieve(
                event: $event,
                listener: 'App\\Listener',
                status: RedeliveryStatus::PendingRetry,
                attemptNumber: 1,
                nextRetryAt: $nextRetryAt,
                lastError: 'RuntimeException: boom',
                createdAt: $now,
                updatedAt: $now,
            );

            self::assertEquals($expected, $redelivery);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_retrieve_reconstructs_a_row_with_supplied_values(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $createdAt = CarbonImmutable::parse('2026-01-01 10:00:00');
        $updatedAt = CarbonImmutable::parse('2026-01-01 10:05:00');
        $nextRetryAt = CarbonImmutable::parse('2026-01-01 10:10:00');

        $redelivery = Redelivery::retrieve(
            event: $event,
            listener: 'App\\Listener',
            status: RedeliveryStatus::Dispatching,
            attemptNumber: 3,
            nextRetryAt: $nextRetryAt,
            lastError: 'RuntimeException: prior',
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );

        $expected = Redelivery::retrieve(
            event: $event,
            listener: 'App\\Listener',
            status: RedeliveryStatus::Dispatching,
            attemptNumber: 3,
            nextRetryAt: $nextRetryAt,
            lastError: 'RuntimeException: prior',
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );

        self::assertEquals($expected, $redelivery);
    }

    public function test_retrieve_rejects_attempt_number_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Redelivery::retrieve(
            event: TestEventFactory::retrieveOrderPlaced(),
            listener: 'App\\Listener',
            status: RedeliveryStatus::PendingRetry,
            attemptNumber: 0,
            nextRetryAt: CarbonImmutable::now(),
            lastError: '',
            createdAt: CarbonImmutable::now(),
            updatedAt: CarbonImmutable::now(),
        );
    }

    public function test_schedule_rejects_attempt_number_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attemptNumber must be at least 1');

        Redelivery::schedule(
            event: TestEventFactory::retrieveOrderPlaced(),
            listener: 'App\\Listener',
            attemptNumber: 0,
            nextRetryAt: CarbonImmutable::now(),
            lastError: new RuntimeException('boom'),
        );
    }

    public function test_schedule_rejects_negative_attempt_number(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Redelivery::schedule(
            event: TestEventFactory::retrieveOrderPlaced(),
            listener: 'App\\Listener',
            attemptNumber: -3,
            nextRetryAt: CarbonImmutable::now(),
            lastError: new RuntimeException('boom'),
        );
    }

    public function test_schedule_rejects_empty_listener(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('listener must not be empty');

        Redelivery::schedule(
            event: TestEventFactory::retrieveOrderPlaced(),
            listener: '',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now(),
            lastError: new RuntimeException('boom'),
        );
    }

    public function test_schedule_rejects_whitespace_only_listener(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Redelivery::schedule(
            event: TestEventFactory::retrieveOrderPlaced(),
            listener: "   \t\n",
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now(),
            lastError: new RuntimeException('boom'),
        );
    }

    public function test_event_id_and_event_name_proxy_to_underlying_event(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $redelivery = self::fresh($event, listener: 'App\\Listener');

        self::assertSame($event->id, $redelivery->eventId());
        self::assertSame($event->name, $redelivery->eventName());
    }

    public function test_is_for_compares_listener_strings(): void
    {
        $redelivery = self::fresh(listener: 'App\\Listener');

        self::assertTrue($redelivery->isFor('App\\Listener'));
        self::assertFalse($redelivery->isFor('App\\Other'));
    }

    public function test_is_due_only_when_pending_and_retry_time_passed(): void
    {
        $now = CarbonImmutable::now();
        $duePending = self::fresh(nextRetryAt: $now->subSecond());
        $futurePending = self::fresh(nextRetryAt: $now->addMinute());

        self::assertTrue($duePending->isDue($now));
        self::assertFalse($futurePending->isDue($now));
    }

    public function test_claim_transitions_pending_to_dispatching(): void
    {
        $claimed = self::fresh()->claim();

        self::assertTrue($claimed->isDispatching());
        self::assertFalse($claimed->isPending());
    }

    public function test_mark_succeeded_transitions_to_succeeded(): void
    {
        $finalised = self::fresh()->claim()->markSucceeded();

        self::assertSame(RedeliveryStatus::Succeeded, $finalised->status);
        self::assertTrue($finalised->isSucceeded());
    }

    public function test_mark_failed_permanently_transitions_to_failed_and_stores_error(): void
    {
        $error = new RuntimeException('final');
        $finalised = self::fresh()->claim()->markFailedPermanently($error);

        self::assertSame(RedeliveryStatus::Failed, $finalised->status);
        self::assertTrue($finalised->isFailed());
        self::assertStringContainsString('final', $finalised->lastError);
    }

    public function test_is_due_is_false_for_non_pending_statuses(): void
    {
        $now = CarbonImmutable::now();

        $dispatching = self::fresh(nextRetryAt: $now->subSecond())->claim();
        $succeeded = self::fresh(nextRetryAt: $now->subSecond())->claim()->markSucceeded();
        $failed = self::fresh(nextRetryAt: $now->subSecond())->claim()->markFailedPermanently(new RuntimeException('x'));

        self::assertFalse($dispatching->isDue($now));
        self::assertFalse($succeeded->isDue($now));
        self::assertFalse($failed->isDue($now));
    }

    public function test_reschedule_to_bumps_attempt_count_and_returns_to_pending(): void
    {
        $error = new RuntimeException('again');
        $next = CarbonImmutable::now()->addMinutes(5);

        $rescheduled = self::fresh(attemptNumber: 1)
            ->claim()
            ->rescheduleTo(attemptNumber: 2, nextRetryAt: $next, lastError: $error);

        self::assertTrue($rescheduled->isPending());
        self::assertSame(2, $rescheduled->attemptNumber);
        self::assertSame($next, $rescheduled->nextRetryAt);
        self::assertStringContainsString('again', $rescheduled->lastError);
    }

    public function test_queue_for_immediate_retry_preserves_attempt_count(): void
    {
        $original = self::fresh(attemptNumber: 4);
        $requeued = $original->claim()->markFailedPermanently(new RuntimeException('x'))->queueForImmediateRetry();

        self::assertTrue($requeued->isPending());
        self::assertSame(4, $requeued->attemptNumber);
    }

    public function test_claim_mutates_and_returns_the_same_instance(): void
    {
        $redelivery = self::fresh();

        $claimed = $redelivery->claim();

        self::assertSame($redelivery, $claimed);
        self::assertTrue($redelivery->isDispatching());
    }

    public function test_claim_rejects_a_non_pending_redelivery(): void
    {
        $redelivery = self::fresh()->claim();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot claim a redelivery in status dispatching');

        $redelivery->claim();
    }

    public function test_mark_succeeded_rejects_a_non_dispatching_redelivery(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot markSucceeded a redelivery in status pending_retry');

        self::fresh()->markSucceeded();
    }

    public function test_mark_failed_permanently_rejects_a_non_dispatching_redelivery(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot markFailedPermanently a redelivery in status pending_retry');

        self::fresh()->markFailedPermanently(new RuntimeException('x'));
    }

    public function test_reschedule_to_rejects_a_non_dispatching_redelivery(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot rescheduleTo a redelivery in status pending_retry');

        self::fresh()->rescheduleTo(2, CarbonImmutable::now(), new RuntimeException('x'));
    }

    public function test_queue_for_immediate_retry_accepts_any_status(): void
    {
        $succeeded = self::fresh()->claim()->markSucceeded();
        $failed = self::fresh()->claim()->markFailedPermanently(new RuntimeException('x'));

        self::assertTrue($succeeded->queueForImmediateRetry()->isPending());
        self::assertTrue($failed->queueForImmediateRetry()->isPending());
    }

    private static function fresh(
        ?RawEvent $event = null,
        string $listener = 'App\\Listener',
        int $attemptNumber = 1,
        ?CarbonImmutable $nextRetryAt = null,
    ): Redelivery {
        return Redelivery::schedule(
            event: $event ?? TestEventFactory::retrieveOrderPlaced(),
            listener: $listener,
            attemptNumber: $attemptNumber,
            nextRetryAt: $nextRetryAt ?? CarbonImmutable::now(),
            lastError: new RuntimeException('initial'),
        );
    }
}
