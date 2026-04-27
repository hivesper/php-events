<?php

namespace Test\Vesper\Tool\Event\Unit\Retry;

use PHPUnit\Framework\TestCase;
use Vesper\Tool\Event\Infrastructure\Retry\NoRetryPolicy;

class NoRetryPolicyTest extends TestCase
{
    public function test_returns_null_for_first_attempt(): void
    {
        $policy = new NoRetryPolicy();

        self::assertNull($policy->nextRetryAt(1));
    }

    public function test_returns_null_for_arbitrary_attempt(): void
    {
        $policy = new NoRetryPolicy();

        self::assertNull($policy->nextRetryAt(42));
    }
}
