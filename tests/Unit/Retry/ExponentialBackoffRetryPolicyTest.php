<?php

namespace Test\Vesper\Tool\Event\Unit\Retry;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vesper\Tool\Event\Infrastructure\Retry\ExponentialBackoffRetryPolicy;

class ExponentialBackoffRetryPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::createFromFormat('Y-m-d H:i:s.u', '2026-04-27 12:00:00.000000'),
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
    }

    public function test_returns_now_plus_first_delay_for_first_attempt(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(delaysMs: [100, 500]);

        $next = $policy->nextRetryAt(1);

        self::assertNotNull($next);
        self::assertSame(100, (int) round(CarbonImmutable::now()->diffInMilliseconds($next)));
    }

    public function test_returns_now_plus_second_delay_for_second_attempt(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(delaysMs: [100, 500]);

        $next = $policy->nextRetryAt(2);

        self::assertNotNull($next);
        self::assertSame(500, (int) round(CarbonImmutable::now()->diffInMilliseconds($next)));
    }

    public function test_returns_null_when_attempts_exhausted(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(delaysMs: [100, 500]);

        self::assertNull($policy->nextRetryAt(3));
        self::assertNull($policy->nextRetryAt(99));
    }

    public function test_default_delays_yield_four_retries_with_in_process_then_persisted_durations(): void
    {
        $policy = new ExponentialBackoffRetryPolicy();
        $now = CarbonImmutable::now();

        self::assertSame(100, (int) round($now->diffInMilliseconds($policy->nextRetryAt(1))));
        self::assertSame(500, (int) round($now->diffInMilliseconds($policy->nextRetryAt(2))));
        self::assertSame(60_000, (int) round($now->diffInMilliseconds($policy->nextRetryAt(3))));
        self::assertSame(300_000, (int) round($now->diffInMilliseconds($policy->nextRetryAt(4))));
        self::assertNull($policy->nextRetryAt(5));
    }

    public function test_rejects_negative_delays(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExponentialBackoffRetryPolicy(delaysMs: [100, -1]);
    }

    public function test_returns_null_for_zero_or_negative_attempt_number(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(delaysMs: [100]);

        self::assertNull($policy->nextRetryAt(0));
        self::assertNull($policy->nextRetryAt(-1));
    }
}
