<?php

namespace Test\Vesper\Tool\Event\_Fixtures;

readonly class OrderPlacedStub
{
    public function __construct(
        public int $orderId,
        public float $total,
    ) {
    }
}
