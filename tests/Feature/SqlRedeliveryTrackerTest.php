<?php

namespace Test\Vesper\Tool\Event\Feature;

use Carbon\CarbonImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;
use Vesper\Tool\Event\Infrastructure\SqlEventStore;
use Vesper\Tool\Event\Infrastructure\SqlRedeliveryTracker;
use Vesper\Tool\Event\RawEvent;

class SqlRedeliveryTrackerTest extends TestCase
{
    private PDO $pdo;
    private SqlEventStore $store;
    private SqlRedeliveryTracker $tracker;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->store = new SqlEventStore($this->pdo);
        $this->tracker = new SqlRedeliveryTracker($this->pdo);
    }

    public function test_schema_is_idempotent_across_multiple_instantiations(): void
    {
        new SqlRedeliveryTracker($this->pdo);
        new SqlRedeliveryTracker($this->pdo);

        self::assertTrue(true);
    }

    public function test_next_due_returns_null_when_table_is_empty(): void
    {
        self::assertNull($this->tracker->nextDue());
    }

    public function test_schedule_then_next_due_round_trip(): void
    {
        $event = $this->insertEvent();

        $this->tracker->schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        );

        $due = $this->tracker->nextDue();

        self::assertNotNull($due);
        self::assertSame($event->id, $due->event->id);
        self::assertSame('App\\Listener', $due->listener);
        self::assertSame(1, $due->attemptNumber);
        self::assertSame($event->name, $due->event->name);
    }

    public function test_next_due_excludes_rows_whose_retry_time_is_in_the_future(): void
    {
        $event = $this->insertEvent();

        $this->tracker->schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->addMinute(),
            lastError: new RuntimeException('boom'),
        );

        self::assertNull($this->tracker->nextDue());
    }

    public function test_schedule_is_idempotent_and_updates_on_repeat(): void
    {
        $event = $this->insertEvent();

        $this->tracker->schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->addMinute(),
            lastError: new RuntimeException('first'),
        );
        $this->tracker->schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 2,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('second'),
        );

        $due = $this->tracker->nextDue();

        self::assertNotNull($due);
        self::assertSame(2, $due->attemptNumber, 'second schedule overwrote the first row');

        $rows = $this->fetchAllRedeliveryRows($event->id);
        self::assertCount(1, $rows, 'no duplicate rows for (event_id, listener)');
    }

    public function test_mark_succeeded_removes_row_from_due_queue(): void
    {
        $event = $this->insertEvent();

        $this->tracker->schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        );

        $this->tracker->markSucceeded($event->id, 'App\\Listener');

        self::assertNull($this->tracker->nextDue());
        self::assertSame('succeeded', $this->fetchRedeliveryStatus($event->id, 'App\\Listener'));
    }

    public function test_mark_failed_permanently_removes_row_from_due_queue(): void
    {
        $event = $this->insertEvent();

        $this->tracker->schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 5,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        );

        $this->tracker->markFailedPermanently($event->id, 'App\\Listener', new RuntimeException('final'));

        self::assertNull($this->tracker->nextDue());
        self::assertSame('failed', $this->fetchRedeliveryStatus($event->id, 'App\\Listener'));
    }

    public function test_process_next_due_runs_handler_inside_a_transaction(): void
    {
        $event = $this->insertEvent();
        $this->tracker->schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        );

        $observedInTransaction = null;

        $this->tracker->processNextDue(function () use (&$observedInTransaction): void {
            $observedInTransaction = $this->pdo->inTransaction();
        });

        self::assertTrue($observedInTransaction, 'handler ran inside an active transaction');
        self::assertFalse($this->pdo->inTransaction(), 'transaction committed after processNextDue returned');
    }

    public function test_process_next_due_commits_handler_side_effects(): void
    {
        $event = $this->insertEvent();
        $this->tracker->schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 5,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        );

        $this->tracker->processNextDue(function () use ($event): void {
            $this->tracker->markFailedPermanently(
                $event->id,
                'App\\Listener',
                new RuntimeException('final'),
            );
        });

        self::assertSame('failed', $this->fetchRedeliveryStatus($event->id, 'App\\Listener'));
        self::assertNull($this->tracker->nextDue());
    }

    public function test_process_next_due_rolls_back_when_handler_throws(): void
    {
        $event = $this->insertEvent();
        $this->tracker->schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        );

        try {
            $this->tracker->processNextDue(function () use ($event): void {
                $this->tracker->markSucceeded($event->id, 'App\\Listener');
                throw new RuntimeException('handler exploded');
            });
            self::fail('expected exception');
        } catch (RuntimeException $e) {
            self::assertSame('handler exploded', $e->getMessage());
        }

        self::assertSame(
            'pending_retry',
            $this->fetchRedeliveryStatus($event->id, 'App\\Listener'),
            'markSucceeded rolled back because the handler threw — caller must catch and defer to keep side effects',
        );
        self::assertFalse($this->pdo->inTransaction(), 'transaction rolled back, not left dangling');
    }

    public function test_process_next_due_is_a_noop_when_nothing_is_due(): void
    {
        $invoked = false;

        $this->tracker->processNextDue(function () use (&$invoked): void {
            $invoked = true;
        });

        self::assertFalse($invoked, 'handler not invoked when no row is due');
        self::assertFalse($this->pdo->inTransaction(), 'transaction committed even when no row was found');
    }

    public function test_retry_now_re_queues_a_permanently_failed_row_preserving_attempt_count(): void
    {
        $event = $this->insertEvent();

        $this->tracker->schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 5,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        );
        $this->tracker->markFailedPermanently($event->id, 'App\\Listener', new RuntimeException('final'));

        $this->tracker->retryNow($event->id, 'App\\Listener');

        $due = $this->tracker->nextDue();
        self::assertNotNull($due);
        self::assertSame(5, $due->attemptNumber, 'attempt count is preserved across retryNow()');
    }

    private function insertEvent(): RawEvent
    {
        $event = TestEventFactory::retrieveOrderPlaced(['order_id' => 1]);
        $this->store->add($event);
        return $event;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllRedeliveryRows(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM event_outbox_redelivery WHERE event_id = :id',
        );
        $stmt->execute(['id' => $eventId]);

        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchRedeliveryStatus(string $eventId, string $listener): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT status FROM event_outbox_redelivery WHERE event_id = :id AND listener = :listener',
        );
        $stmt->execute(['id' => $eventId, 'listener' => $listener]);
        $value = $stmt->fetchColumn();
        self::assertIsString($value);
        return $value;
    }
}
