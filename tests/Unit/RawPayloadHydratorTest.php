<?php

namespace Test\Tcds\Io\Ray\Unit;

use PHPUnit\Framework\TestCase;
use Tcds\Io\Ray\Infrastructure\RawPayloadHydrator;

class RawPayloadHydratorTest extends TestCase
{
    private RawPayloadHydrator $hydrator;

    protected function setUp(): void
    {
        $this->hydrator = new RawPayloadHydrator();
    }

    public function test_maps_payload_keys_as_properties(): void
    {
        $result = $this->hydrator->hydrate('order.placed', ['order_id' => 42, 'total' => 9.99]);

        self::assertSame(42, $result->order_id);
        self::assertSame(9.99, $result->total);
    }

    public function test_empty_payload_returns_empty_object(): void
    {
        $result = $this->hydrator->hydrate('order.placed', []);

        self::assertSame([], (array) $result);
    }

    public function test_event_name_does_not_affect_output(): void
    {
        $payload = ['foo' => 'bar'];

        $a = $this->hydrator->hydrate('event.one', $payload);
        $b = $this->hydrator->hydrate('event.two', $payload);

        self::assertEquals($a, $b);
    }
}
