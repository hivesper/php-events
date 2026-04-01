<?php

namespace Test\Vesper\Tool\Event\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vesper\Tool\Event\Infrastructure\JacksonHydrator;
use Test\Vesper\Tool\Event\_Fixtures\OrderPlacedStub;

class JacksonHydratorTest extends TestCase
{
    private JacksonHydrator $hydrator;

    protected function setUp(): void
    {
        $this->hydrator = new JacksonHydrator();
    }

    public function test_maps_payload_to_class_when_closure_has_class_typed_arg(): void
    {
        $subscriber = function (OrderPlacedStub $event): void {};

        /** @var OrderPlacedStub $result */
        $result = $this->hydrator->hydrate('OrderPlacedStub', ['orderId' => 42, 'total' => 9.99], $subscriber);

        self::assertEquals(new OrderPlacedStub(42, 9.99), $result);
    }

    public function test_maps_payload_to_class_when_invokable_object_has_class_typed_arg(): void
    {
        $subscriber = new class {
            public function __invoke(OrderPlacedStub $event): void {}
        };

        $result = $this->hydrator->hydrate('OrderPlacedStub', ['orderId' => 7, 'total' => 1.50], $subscriber);

        self::assertEquals(new OrderPlacedStub(7, 1.50), $result);
    }

    public function test_falls_back_to_stdclass_when_closure_has_no_args(): void
    {
        $subscriber = function (): void {};

        $result = $this->hydrator->hydrate('OrderPlacedStub', ['orderId' => 1, 'total' => 5.00], $subscriber);

        self::assertEquals((object) ['orderId' => 1, 'total' => 5.00], $result);
    }

    public function test_falls_back_to_stdclass_when_closure_has_untyped_arg(): void
    {
        $subscriber = function ($event): void {};

        $result = $this->hydrator->hydrate('OrderPlacedStub', ['orderId' => 1, 'total' => 5.00], $subscriber);

        self::assertEquals((object) ['orderId' => 1, 'total' => 5.00], $result);
    }

    public function test_falls_back_to_stdclass_when_closure_has_object_type_hint(): void
    {
        $subscriber = function (object $event): void {};

        $result = $this->hydrator->hydrate('OrderPlacedStub', ['orderId' => 3, 'total' => 2.00], $subscriber);

        self::assertEquals((object) ['orderId' => 3, 'total' => 2.00], $result);
    }

    public function test_throws_for_primitive_type_hint(): void
    {
        $subscriber = function (string $event): void {};

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Subscriber's first argument must be typed as object or a class, got 'string'.");

        $this->hydrator->hydrate('OrderPlacedStub', ['orderId' => 1, 'total' => 1.00], $subscriber);
    }

    public function test_throws_for_array_type_hint(): void
    {
        $subscriber = function (array $payload): void {};

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Subscriber's first argument must be typed as object or a class, got 'array'.");

        $this->hydrator->hydrate('OrderPlacedStub', ['orderId' => 1, 'total' => 1.00], $subscriber);
    }
}
