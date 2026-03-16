<?php

namespace Test\Tcds\Io\Ray\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Tcds\Io\Ray\RayEvent;
use Tcds\Io\Ray\RayEventStatus;

class RayEventTest extends TestCase
{
    // ── create() ─────────────────────────────────────────────────────────────

    public function test_create_returns_a_ray_event(): void
    {
        $event = RayEvent::create('order.placed', [], CarbonImmutable::now());

        self::assertInstanceOf(RayEvent::class, $event);
    }

    public function test_create_sets_name(): void
    {
        $event = RayEvent::create('order.placed', [], CarbonImmutable::now());

        self::assertSame('order.placed', $event->name);
    }

    public function test_create_sets_payload(): void
    {
        $payload = ['order_id' => 42, 'total' => 99.99];
        $event = RayEvent::create('order.placed', $payload, CarbonImmutable::now());

        self::assertSame($payload, $event->payload);
    }

    public function test_create_defaults_status_to_pending(): void
    {
        $event = RayEvent::create('order.placed', [], CarbonImmutable::now());

        self::assertSame(RayEventStatus::pending, $event->status);
    }

    public function test_create_sets_publish_at(): void
    {
        $publishAt = CarbonImmutable::parse('2030-06-15 12:00:00');
        $event = RayEvent::create('order.placed', [], $publishAt);

        self::assertSame($publishAt->toIso8601String(), $event->publishAt->toIso8601String());
    }

    public function test_create_generates_non_empty_id(): void
    {
        $event = RayEvent::create('order.placed', [], CarbonImmutable::now());

        self::assertNotEmpty($event->id);
    }

    public function test_create_generates_unique_ids(): void
    {
        $a = RayEvent::create('order.placed', [], CarbonImmutable::now());
        $b = RayEvent::create('order.placed', [], CarbonImmutable::now());

        self::assertNotSame($a->id, $b->id);
    }

    // ── retrieve() ───────────────────────────────────────────────────────────

    public function test_retrieve_preserves_id(): void
    {
        $event = $this->makeRetrievedEvent(id: 'custom-id-123');

        self::assertSame('custom-id-123', $event->id);
    }

    public function test_retrieve_preserves_name(): void
    {
        $event = $this->makeRetrievedEvent(name: 'payment.failed');

        self::assertSame('payment.failed', $event->name);
    }

    public function test_retrieve_preserves_status(): void
    {
        $event = $this->makeRetrievedEvent(status: RayEventStatus::processed);

        self::assertSame(RayEventStatus::processed, $event->status);
    }

    public function test_retrieve_preserves_payload(): void
    {
        $payload = ['amount' => 50, 'currency' => 'USD'];
        $event = $this->makeRetrievedEvent(payload: $payload);

        self::assertSame($payload, $event->payload);
    }

    public function test_retrieve_preserves_created_at(): void
    {
        $createdAt = CarbonImmutable::parse('2025-01-01 08:00:00');
        $event = $this->makeRetrievedEvent(createdAt: $createdAt);

        self::assertSame($createdAt->toIso8601String(), $event->createdAt->toIso8601String());
    }

    public function test_retrieve_preserves_publish_at(): void
    {
        $publishAt = CarbonImmutable::parse('2025-12-31 23:59:59');
        $event = $this->makeRetrievedEvent(publishAt: $publishAt);

        self::assertSame($publishAt->toIso8601String(), $event->publishAt->toIso8601String());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeRetrievedEvent(
        string $id = 'test-id',
        string $name = 'order.placed',
        RayEventStatus $status = RayEventStatus::pending,
        array $payload = [],
        ?CarbonImmutable $createdAt = null,
        ?CarbonImmutable $publishAt = null,
    ): RayEvent {
        return RayEvent::retrieve(
            id: $id,
            name: $name,
            status: $status,
            payload: $payload,
            createdAt: $createdAt ?? CarbonImmutable::now(),
            publishAt: $publishAt ?? CarbonImmutable::now(),
        );
    }
}
