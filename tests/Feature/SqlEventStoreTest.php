<?php

namespace Test\Tcds\Io\Ray\Feature;

use Carbon\CarbonImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tcds\Io\Ray\Infrastructure\SqlEventStore;
use Tcds\Io\Ray\RayEvent;

class SqlEventStoreTest extends TestCase
{
    private PDO $pdo;
    private SqlEventStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // SqlEventStore calls ensureOutboxSchema() in the constructor
        $this->store = new SqlEventStore($this->pdo);
    }

    // ── next() ────────────────────────────────────────────────────────────────

    public function test_next_returns_null_when_store_is_empty(): void
    {
        self::assertNull($this->store->next());
    }

    public function test_next_returns_null_when_no_pending_events(): void
    {
        $event = self::createEvent('order.placed');
        $this->store->add($event);
        $this->store->next(); // consumes it

        self::assertNull($this->store->next());
    }

    // ── add() + next() ────────────────────────────────────────────────────────

    public function test_add_persists_an_event_that_next_can_retrieve(): void
    {
        $event = self::createEvent('order.placed', ['order_id' => 99]);
        $this->store->add($event);

        $retrieved = $this->store->next();

        self::assertNotNull($retrieved);
        self::assertSame($event->id, $retrieved->id);
    }

    public function test_next_returns_correct_name(): void
    {
        $this->store->add(self::createEvent('payment.received'));

        $retrieved = $this->store->next();

        self::assertNotNull($retrieved);
        self::assertSame('payment.received', $retrieved->name);
    }

    public function test_next_returns_correct_payload(): void
    {
        $payload = ['amount' => 150, 'currency' => 'USD'];
        $this->store->add(self::createEvent('payment.received', $payload));

        $retrieved = $this->store->next();

        self::assertNotNull($retrieved);
        self::assertSame($payload, $retrieved->payload);
    }

    public function test_next_marks_event_as_processed_so_it_is_not_returned_again(): void
    {
        $this->store->add(self::createEvent('order.placed'));
        $this->store->next(); // marks as processed

        self::assertNull($this->store->next());
    }

    public function test_next_returns_events_ordered_by_publish_at(): void
    {
        $later = self::createEvent('b', [], CarbonImmutable::now()->subSeconds(5));
        $earlier = self::createEvent('a', [], CarbonImmutable::now()->subMinutes(10));

        $this->store->add($later);
        $this->store->add($earlier);

        $first = $this->store->next();
        $second = $this->store->next();

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame('a', $first->name);
        self::assertSame('b', $second->name);
    }

    public function test_next_only_returns_events_whose_publish_at_is_in_the_past(): void
    {
        $future = self::createEvent('future.event', [], CarbonImmutable::now()->addHour());
        $this->store->add($future);

        self::assertNull($this->store->next());
    }

    public function test_next_returns_event_with_pending_status_from_db(): void
    {
        $this->store->add(self::createEvent('order.placed'));

        // A second store instance over the same DB sees the event as processed
        $anotherStore = new SqlEventStore($this->pdo);
        $first = $anotherStore->next();

        self::assertNotNull($first);

        // Consuming it means a third retrieval should see nothing
        self::assertNull($anotherStore->next());
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_is_idempotent_across_multiple_instantiations(): void
    {
        // Creating a second store on the same DB should not throw or recreate tables
        new SqlEventStore($this->pdo);
        new SqlEventStore($this->pdo);

        self::assertTrue(true);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    private static function createEvent(
        string $name,
        array $payload = [],
        ?CarbonImmutable $publishAt = null,
    ): RayEvent {
        return RayEvent::create(
            name: $name,
            payload: $payload,
            publishAt: $publishAt ?? CarbonImmutable::now()->subSecond(),
        );
    }
}
