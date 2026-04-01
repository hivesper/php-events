<?php

namespace Test\Vesper\Tool\Event\_Fixtures;

use Carbon\CarbonImmutable;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\RawEventStatus;

class TestEventFactory
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function retrieveOrderPlaced(array $payload = []): RawEvent
    {
        return self::retrieve(name: 'order.placed', payload: $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function retrievePaymentReceived(array $payload = []): RawEvent
    {
        return self::retrieve(name: 'payment.received', payload: $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function retrieve(string $name, array $payload = []): RawEvent
    {
        return RawEvent::retrieve(
            id: uniqid(),
            name: $name,
            status: RawEventStatus::pending,
            payload: $payload,
            createdAt: CarbonImmutable::now(),
            publishAt: CarbonImmutable::now(),
        );
    }
}
