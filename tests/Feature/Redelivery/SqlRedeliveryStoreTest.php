<?php

namespace Test\Vesper\Tool\Event\Feature\Redelivery;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;
use Vesper\Tool\Event\Infrastructure\Redelivery\SqlRedeliveryStore;
use Vesper\Tool\Event\Infrastructure\SqlEventStore;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\Redelivery\Redelivery;
use Vesper\Tool\Event\Redelivery\RedeliveryStatus;

class SqlRedeliveryStoreTest extends TestCase
{
    private PDO $pdo;
    private SqlEventStore $eventStore;
    private SqlRedeliveryStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->eventStore = new SqlEventStore($this->pdo);
        $this->store = new SqlRedeliveryStore($this->pdo);
    }

    public function test_schema_is_idempotent_across_multiple_instantiations(): void
    {
        new SqlRedeliveryStore($this->pdo);
        new SqlRedeliveryStore($this->pdo);

        self::assertTrue(true);
    }

    public function test_next_returns_null_when_table_is_empty(): void
    {
        self::assertNull($this->store->next());
    }

    public function test_schedule_then_next_round_trip(): void
    {
        $now = CarbonImmutable::parse('2026-05-16 12:00:00');
        CarbonImmutable::setTestNow($now);

        try {
            $event = $this->insertEvent();
            $nextRetryAt = $now->subSecond();
            $this->store->schedule(Redelivery::schedule(
                event: $event,
                listener: 'App\\Listener',
                attemptNumber: 1,
                nextRetryAt: $nextRetryAt,
                lastError: new RuntimeException('boom'),
            ));

            $due = $this->store->next();

            $expected = Redelivery::retrieve(
                event: $event,
                listener: 'App\\Listener',
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

    public function test_next_claims_row_so_a_second_call_skips_it(): void
    {
        $event = $this->insertEvent();
        $this->store->schedule(Redelivery::schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));

        $first = $this->store->next();
        $second = $this->store->next();

        self::assertNotNull($first);
        self::assertNull($second);
        self::assertSame('dispatching', $this->fetchRedeliveryStatus($event->id, 'App\\Listener'));
    }

    public function test_next_excludes_rows_whose_retry_time_is_in_the_future(): void
    {
        $event = $this->insertEvent();

        $this->store->schedule(Redelivery::schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->addMinute(),
            lastError: new RuntimeException('boom'),
        ));

        self::assertNull($this->store->next());
    }

    public function test_schedule_is_a_noop_when_a_row_already_exists(): void
    {
        $event = $this->insertEvent();

        $this->store->schedule(Redelivery::schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 2,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('first'),
        ));
        $this->store->schedule(Redelivery::schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->addMinute(),
            lastError: new RuntimeException('replay-from-recovered-event'),
        ));

        $due = $this->store->next();

        self::assertNotNull($due);
        self::assertSame(2, $due->attemptNumber);
        self::assertStringContainsString('first', $due->lastError);
        self::assertCount(1, $this->fetchAllRedeliveryRows($event->id));
    }

    public function test_update_with_succeeded_finalises_a_claimed_row(): void
    {
        $event = $this->insertEvent();
        $this->store->schedule(Redelivery::schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));
        $claimed = $this->store->next();
        self::assertNotNull($claimed);

        $this->store->update($claimed->markSucceeded());

        self::assertSame('succeeded', $this->fetchRedeliveryStatus($event->id, 'App\\Listener'));
        self::assertNull($this->store->next());
    }

    public function test_update_is_a_noop_when_row_is_not_in_dispatching(): void
    {
        $event = $this->insertEvent();
        $redelivery = Redelivery::schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        );
        $this->store->schedule($redelivery);

        $this->store->update($redelivery->claim()->markSucceeded());

        self::assertSame('pending_retry', $this->fetchRedeliveryStatus($event->id, 'App\\Listener'));
    }

    public function test_update_with_failed_permanently_finalises_a_claimed_row(): void
    {
        $event = $this->insertEvent();
        $this->store->schedule(Redelivery::schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 5,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));
        $claimed = $this->store->next();
        self::assertNotNull($claimed);

        $this->store->update($claimed->markFailedPermanently(new RuntimeException('final')));

        self::assertSame('failed', $this->fetchRedeliveryStatus($event->id, 'App\\Listener'));
        self::assertNull($this->store->next());
    }

    public function test_retry_now_re_queues_a_permanently_failed_row_preserving_attempt_count(): void
    {
        $event = $this->insertEvent();

        $this->store->schedule(Redelivery::schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 5,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));
        $claimed = $this->store->next();
        self::assertNotNull($claimed);
        $this->store->update($claimed->markFailedPermanently(new RuntimeException('final')));

        $this->store->retryNow($event->id, 'App\\Listener');

        $due = $this->store->next();
        self::assertNotNull($due);
        self::assertSame(5, $due->attemptNumber);
    }

    public function test_recover_stuck_redeliveries_resets_dispatching_rows_older_than_threshold(): void
    {
        $event = $this->insertEvent();
        $this->store->schedule(Redelivery::schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));
        $this->store->next();
        $this->backdateUpdatedAt($event->id, 'App\\Listener', CarbonImmutable::now()->subHour());

        $recovered = $this->store->recoverStuckRedeliveries(CarbonInterval::minutes(30));

        self::assertSame(1, $recovered);
        self::assertSame('pending_retry', $this->fetchRedeliveryStatus($event->id, 'App\\Listener'));
    }

    public function test_recover_stuck_redeliveries_skips_rows_within_threshold(): void
    {
        $event = $this->insertEvent();
        $this->store->schedule(Redelivery::schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));
        $this->store->next();

        $recovered = $this->store->recoverStuckRedeliveries(CarbonInterval::minutes(30));

        self::assertSame(0, $recovered);
        self::assertSame('dispatching', $this->fetchRedeliveryStatus($event->id, 'App\\Listener'));
    }

    public function test_recover_stuck_redeliveries_does_not_touch_pending_or_terminal_rows(): void
    {
        $event = $this->insertEvent();
        $this->store->schedule(Redelivery::schedule(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now()->subSecond(),
            lastError: new RuntimeException('boom'),
        ));
        $this->backdateUpdatedAt($event->id, 'App\\Listener', CarbonImmutable::now()->subHour());

        $recovered = $this->store->recoverStuckRedeliveries(CarbonInterval::minutes(30));

        self::assertSame(0, $recovered);
        self::assertSame('pending_retry', $this->fetchRedeliveryStatus($event->id, 'App\\Listener'));
    }

    private function insertEvent(): RawEvent
    {
        $event = TestEventFactory::retrieveOrderPlaced(['order_id' => 1]);
        $this->eventStore->add($event);
        return $event;
    }

    private function backdateUpdatedAt(string $eventId, string $listener, CarbonImmutable $newUpdatedAt): void
    {
        $this->pdo->prepare(
            <<<SQL
                UPDATE event_outbox_redelivery
                    SET updated_at = :updated_at
                    WHERE event_id = :event_id AND listener = :listener
                SQL,
        )->execute([
            'updated_at' => $newUpdatedAt->format('Y-m-d H:i:s.u'),
            'event_id' => $eventId,
            'listener' => $listener,
        ]);
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
