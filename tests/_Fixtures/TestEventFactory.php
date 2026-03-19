<?php

namespace Test\Tcds\Io\Ray\_Fixtures;

use Carbon\CarbonImmutable;
use Tcds\Io\Ray\RayEvent;
use Tcds\Io\Ray\RayEventStatus;

class TestEventFactory
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function retrieveOrderPlaced(array $payload = []): RayEvent
    {
        return self::retrieve(name: 'order.placed', payload: $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function retrievePaymentReceived(array $payload = []): RayEvent
    {
        return self::retrieve(name: 'payment.received', payload: $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function retrieve(string $name, array $payload = []): RayEvent
    {
        return RayEvent::retrieve(
            id: uniqid(),
            name: $name,
            status: RayEventStatus::pending,
            payload: $payload,
            createdAt: CarbonImmutable::now(),
            publishAt: CarbonImmutable::now(),
        );
    }
}
