<?php

namespace Test\Vesper\Tool\Event\Feature;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use PDO;
use PHPUnit\Framework\TestCase;
use Vesper\Tool\Event\Infrastructure\SqlEventStore;
use Vesper\Tool\Event\RawEvent;

class SqlEventStoreRecoverStuckTest extends TestCase
{
    private PDO $pdo;
    private SqlEventStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->store = new SqlEventStore($this->pdo);
    }

    public function test_recovers_processing_event_whose_audit_row_is_older_than_threshold(): void
    {
        $event = self::createEvent();
        $this->store->add($event);

        $this->store->next(); // pending → processing
        $this->backdateLatestProcessingAuditRow($event->id, CarbonImmutable::now()->subHour());

        $recovered = $this->store->recoverStuckEvents(CarbonInterval::minutes(30));

        self::assertSame(1, $recovered);
        self::assertSame('pending', $this->fetchEventStatus($event->id));
    }

    public function test_does_not_recover_processing_event_whose_audit_row_is_within_threshold(): void
    {
        $event = self::createEvent();
        $this->store->add($event);

        $this->store->next();
        // Don't backdate — audit row is fresh.

        $recovered = $this->store->recoverStuckEvents(CarbonInterval::minutes(30));

        self::assertSame(0, $recovered);
        self::assertSame('processing', $this->fetchEventStatus($event->id));
    }

    public function test_does_not_touch_pending_events(): void
    {
        $event = self::createEvent();
        $this->store->add($event);
        // Skip next() — row stays in pending.

        $recovered = $this->store->recoverStuckEvents(CarbonInterval::seconds(0));

        self::assertSame(0, $recovered);
        self::assertSame('pending', $this->fetchEventStatus($event->id));
    }

    public function test_does_not_touch_processed_events(): void
    {
        $event = self::createEvent();
        $this->store->add($event);
        $this->store->next();
        $this->store->markProcessed($event->id);
        $this->backdateLatestProcessingAuditRow($event->id, CarbonImmutable::now()->subHour());

        $recovered = $this->store->recoverStuckEvents(CarbonInterval::minutes(30));

        self::assertSame(0, $recovered);
        self::assertSame('processed', $this->fetchEventStatus($event->id));
    }

    public function test_writes_a_recovery_audit_row(): void
    {
        $event = self::createEvent();
        $this->store->add($event);
        $this->store->next();
        $this->backdateLatestProcessingAuditRow($event->id, CarbonImmutable::now()->subHour());

        $this->store->recoverStuckEvents(CarbonInterval::minutes(30));

        $recoveryRows = array_values(array_filter(
            $this->fetchAuditRows($event->id),
            fn(array $row): bool => $row['error_message'] === 'Recovered from stuck processing state',
        ));

        self::assertCount(1, $recoveryRows, 'exactly one recovery audit row was written');
        self::assertSame('pending', $recoveryRows[0]['status']);
    }

    public function test_recovered_event_can_be_processed_again(): void
    {
        $event = self::createEvent();
        $this->store->add($event);
        $this->store->next();
        $this->backdateLatestProcessingAuditRow($event->id, CarbonImmutable::now()->subHour());

        $this->store->recoverStuckEvents(CarbonInterval::minutes(30));

        $reclaimed = $this->store->next();

        self::assertNotNull($reclaimed);
        self::assertSame($event->id, $reclaimed->id);
    }

    public function test_recovers_multiple_stuck_events_in_one_call(): void
    {
        $eventA = self::createEvent();
        $eventB = self::createEvent();
        $this->store->add($eventA);
        $this->store->add($eventB);

        $this->store->next();
        $this->store->next();
        $this->backdateLatestProcessingAuditRow($eventA->id, CarbonImmutable::now()->subHour());
        $this->backdateLatestProcessingAuditRow($eventB->id, CarbonImmutable::now()->subHour());

        $recovered = $this->store->recoverStuckEvents(CarbonInterval::minutes(30));

        self::assertSame(2, $recovered);
        self::assertSame('pending', $this->fetchEventStatus($eventA->id));
        self::assertSame('pending', $this->fetchEventStatus($eventB->id));
    }

    public function test_returns_zero_when_no_stuck_events_exist(): void
    {
        self::assertSame(0, $this->store->recoverStuckEvents(CarbonInterval::minutes(30)));
    }

    private function backdateLatestProcessingAuditRow(string $eventId, CarbonImmutable $newCreatedAt): void
    {
        $this->pdo->prepare(
            <<<SQL
            UPDATE event_outbox_status
                SET created_at = :created_at
                WHERE event_id = :event_id AND status = 'processing'
            SQL,
        )->execute([
            'event_id' => $eventId,
            'created_at' => $newCreatedAt->format('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * @return array<int, array{status: string, error_message: ?string}>
     */
    private function fetchAuditRows(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, error_message FROM event_outbox_status WHERE event_id = :id ORDER BY created_at',
        );
        $stmt->execute(['id' => $eventId]);

        /** @var array<int, array{status: string, error_message: ?string}> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchEventStatus(string $eventId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM event_outbox WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        $value = $stmt->fetchColumn();
        self::assertIsString($value);
        return $value;
    }

    private static function createEvent(): RawEvent
    {
        return RawEvent::create(
            name: 'order.placed',
            payload: [],
            publishAt: CarbonImmutable::now()->subSecond(),
        );
    }
}
