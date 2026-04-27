<?php

namespace Test\Vesper\Tool\Event\Feature;

use Carbon\CarbonImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Vesper\Tool\Event\Infrastructure\SqlEventStore;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\RawEventStatus;

class SqlEventStoreAuditTest extends TestCase
{
    private PDO $pdo;
    private SqlEventStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->store = new SqlEventStore($this->pdo);
    }

    public function test_add_inserts_pending_audit_row(): void
    {
        $event = self::createEvent('order.placed');

        $this->store->add($event);

        $rows = $this->fetchAuditRows($event->id);

        self::assertSame([['status' => 'pending', 'error_message' => null]], $rows);
    }

    public function test_next_transitions_event_to_processing_and_inserts_audit_row(): void
    {
        $event = self::createEvent('order.placed');
        $this->store->add($event);

        $retrieved = $this->store->next();

        self::assertNotNull($retrieved);
        self::assertSame(RawEventStatus::processing, $retrieved->status, 'returned RawEvent reflects new status');
        self::assertSame('processing', $this->fetchEventStatus($event->id), 'event_outbox row was flipped to processing');

        $rows = $this->fetchAuditRows($event->id);
        self::assertCount(2, $rows);
        self::assertSame('pending', $rows[0]['status']);
        self::assertSame('processing', $rows[1]['status']);
    }

    public function test_mark_processed_advances_event_and_inserts_audit_row(): void
    {
        $event = self::createEvent('order.placed');
        $this->store->add($event);
        $this->store->next();

        $this->store->markProcessed($event->id);

        self::assertSame('processed', $this->fetchEventStatus($event->id));

        $rows = $this->fetchAuditRows($event->id);
        self::assertCount(3, $rows);
        self::assertSame('pending', $rows[0]['status']);
        self::assertSame('processing', $rows[1]['status']);
        self::assertSame('processed', $rows[2]['status']);
    }

    public function test_next_does_not_pick_up_a_row_already_in_processing(): void
    {
        $event = self::createEvent('order.placed');
        $this->store->add($event);
        $this->store->next(); // pending → processing

        // A second call must not pick up the processing row (it's not pending anymore).
        self::assertNull($this->store->next());
    }

    public function test_mark_processed_is_a_noop_when_row_is_not_in_processing(): void
    {
        $event = self::createEvent('order.placed');
        $this->store->add($event);

        // Skip next(); call markProcessed directly. The guard `WHERE status='processing'` should
        // make the UPDATE a no-op for a row still in 'pending'.
        $this->store->markProcessed($event->id);

        self::assertSame('pending', $this->fetchEventStatus($event->id), 'pending row is left untouched');
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

    /**
     * @param array<string, mixed> $payload
     */
    private static function createEvent(
        string $name,
        array $payload = [],
        ?CarbonImmutable $publishAt = null,
    ): RawEvent {
        return RawEvent::create(
            name: $name,
            payload: $payload,
            publishAt: $publishAt ?? CarbonImmutable::now()->subSecond(),
        );
    }
}
