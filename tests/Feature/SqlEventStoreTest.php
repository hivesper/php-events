<?php

namespace Test\Vesper\Tool\Event\Feature;

use Carbon\CarbonImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Vesper\Tool\Event\Infrastructure\Schema\SqliteEventStoreSchema;
use Vesper\Tool\Event\Infrastructure\SqlEventStore;
use Vesper\Tool\Event\RawEvent;

class SqlEventStoreTest extends TestCase
{
    private PDO $pdo;
    private SqlEventStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        SqliteEventStoreSchema::create($this->pdo);

        $this->store = new SqlEventStore($this->pdo);
    }

    public function test_next_returns_null_when_store_is_empty(): void
    {
        self::assertNull($this->store->next());
    }

    public function test_next_returns_null_when_no_pending_events(): void
    {
        $event = self::createEvent('order.placed');
        $this->store->add($event);
        $this->store->next();

        self::assertNull($this->store->next());
    }

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
        $this->store->next();

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

    public function test_next_returns_event_whose_publish_at_is_milliseconds_in_the_past_within_same_second(): void
    {
        $now = CarbonImmutable::createFromFormat('Y-m-d H:i:s.u', '2024-01-01 12:00:00.800000');
        CarbonImmutable::setTestNow($now);

        try {
            $publishAt = CarbonImmutable::createFromFormat('Y-m-d H:i:s.u', '2024-01-01 12:00:00.500000');
            $event = self::createEvent('order.placed', [], $publishAt);
            $this->store->add($event);

            $retrieved = $this->store->next();

            self::assertNotNull($retrieved);
            self::assertSame($event->id, $retrieved->id);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_next_returns_event_with_pending_status_from_db(): void
    {
        $this->store->add(self::createEvent('order.placed'));

        $anotherStore = new SqlEventStore($this->pdo);
        $first = $anotherStore->next();

        self::assertNotNull($first);
        self::assertNull($anotherStore->next());
    }

    public function test_schema_create_is_idempotent(): void
    {
        SqliteEventStoreSchema::create($this->pdo);
        SqliteEventStoreSchema::create($this->pdo);

        self::assertTrue(true);
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
