<?php

namespace Test\Tcds\Io\Ray\_Fixtures;

readonly class OrderPlacedStub
{
    public function __construct(
        public int $orderId,
        public float $total,
    ) {
    }
}
