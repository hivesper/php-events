<?php

namespace Test\Vesper\Tool\Event\Unit;

use Carbon\CarbonImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\RawEventStatus;

class RawEventTest extends TestCase
{
    public function test_create_returns_a_pending_event_with_supplied_values(): void
    {
        $now = CarbonImmutable::parse('2026-05-16 12:00:00');
        $publishAt = CarbonImmutable::parse('2030-06-15 12:00:00');
        CarbonImmutable::setTestNow($now);

        try {
            $event = RawEvent::create('order.placed', ['order_id' => 42], $publishAt);

            $expected = RawEvent::retrieve(
                id: $event->id,
                name: 'order.placed',
                status: RawEventStatus::pending,
                payload: ['order_id' => 42],
                createdAt: $now,
                publishAt: $publishAt,
            );

            self::assertEquals($expected, $event);
            self::assertNotEmpty($event->id);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_create_generates_unique_ids(): void
    {
        $a = RawEvent::create('order.placed', [], CarbonImmutable::now());
        $b = RawEvent::create('order.placed', [], CarbonImmutable::now());

        self::assertNotSame($a->id, $b->id);
    }

    public function test_retrieve_reconstructs_an_event_from_supplied_values(): void
    {
        $createdAt = CarbonImmutable::parse('2025-01-01 08:00:00');
        $publishAt = CarbonImmutable::parse('2025-12-31 23:59:59');
        $payload = ['amount' => 50, 'currency' => 'USD'];

        $event = RawEvent::retrieve(
            id: 'custom-id-123',
            name: 'payment.failed',
            status: RawEventStatus::processed,
            payload: $payload,
            createdAt: $createdAt,
            publishAt: $publishAt,
        );

        $expected = RawEvent::retrieve(
            id: 'custom-id-123',
            name: 'payment.failed',
            status: RawEventStatus::processed,
            payload: $payload,
            createdAt: $createdAt,
            publishAt: $publishAt,
        );

        self::assertEquals($expected, $event);
    }

    public function test_claim_transitions_pending_to_processing(): void
    {
        $event = RawEvent::create('order.placed', [], CarbonImmutable::now());

        $claimed = $event->claim();

        self::assertSame($event, $claimed);
        self::assertSame(RawEventStatus::processing, $event->status);
    }

    public function test_claim_rejects_non_pending_event(): void
    {
        $event = self::retrievedEvent(status: RawEventStatus::processing);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot claim an event in status processing');

        $event->claim();
    }

    public function test_mark_processed_transitions_processing_to_processed(): void
    {
        $event = RawEvent::create('order.placed', [], CarbonImmutable::now());
        $event->claim();

        $processed = $event->markProcessed();

        self::assertSame($event, $processed);
        self::assertSame(RawEventStatus::processed, $event->status);
    }

    public function test_mark_processed_rejects_pending_event(): void
    {
        $event = RawEvent::create('order.placed', [], CarbonImmutable::now());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot markProcessed an event in status pending');

        $event->markProcessed();
    }

    private static function retrievedEvent(
        string $id = 'test-id',
        string $name = 'order.placed',
        RawEventStatus $status = RawEventStatus::pending,
        array $payload = [],
        ?CarbonImmutable $createdAt = null,
        ?CarbonImmutable $publishAt = null,
    ): RawEvent {
        return RawEvent::retrieve(
            id: $id,
            name: $name,
            status: $status,
            payload: $payload,
            createdAt: $createdAt ?? CarbonImmutable::now(),
            publishAt: $publishAt ?? CarbonImmutable::now(),
        );
    }
}
