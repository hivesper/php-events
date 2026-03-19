<?php

namespace Test\Tcds\Io\Ray\Unit;

use PHPUnit\Framework\TestCase;
use Tcds\Io\Ray\Infrastructure\PublicPropertiesSerializer;
use Test\Tcds\Io\Ray\_Fixtures\EmptyEventStub;
use Test\Tcds\Io\Ray\_Fixtures\OrderPlacedStub;

class PublicPropertiesSerializerTest extends TestCase
{
    private PublicPropertiesSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new PublicPropertiesSerializer();
    }

    public function test_derives_name_from_short_class_name(): void
    {
        $result = $this->serializer->serialize(new OrderPlacedStub(1, 9.99));

        self::assertSame('OrderPlacedStub', $result->name);
    }

    public function test_serializes_public_properties_as_payload(): void
    {
        $result = $this->serializer->serialize(new OrderPlacedStub(42, 9.99));

        self::assertSame(['orderId' => 42, 'total' => 9.99], $result->payload);
    }

    public function test_empty_public_properties_yields_empty_payload(): void
    {
        $result = $this->serializer->serialize(new EmptyEventStub());

        self::assertSame([], $result->payload);
    }
}
