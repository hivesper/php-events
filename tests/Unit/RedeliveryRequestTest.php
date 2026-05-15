<?php

namespace Test\Vesper\Tool\Event\Unit;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;
use Vesper\Tool\Event\RedeliveryRequest;

class RedeliveryRequestTest extends TestCase
{
    public function test_constructs_with_valid_inputs(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $error = new RuntimeException('boom');
        $nextRetryAt = CarbonImmutable::now()->addMinute();

        $request = new RedeliveryRequest(
            event: $event,
            listener: 'App\\Listener',
            attemptNumber: 1,
            nextRetryAt: $nextRetryAt,
            lastError: $error,
        );

        self::assertSame($event, $request->event);
        self::assertSame('App\\Listener', $request->listener);
        self::assertSame(1, $request->attemptNumber);
        self::assertSame($nextRetryAt, $request->nextRetryAt);
        self::assertSame($error, $request->lastError);
    }

    public function test_rejects_attempt_number_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attemptNumber must be at least 1');

        new RedeliveryRequest(
            event: TestEventFactory::retrieveOrderPlaced(),
            listener: 'App\\Listener',
            attemptNumber: 0,
            nextRetryAt: CarbonImmutable::now(),
            lastError: new RuntimeException('boom'),
        );
    }

    public function test_rejects_negative_attempt_number(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RedeliveryRequest(
            event: TestEventFactory::retrieveOrderPlaced(),
            listener: 'App\\Listener',
            attemptNumber: -3,
            nextRetryAt: CarbonImmutable::now(),
            lastError: new RuntimeException('boom'),
        );
    }

    public function test_rejects_empty_listener(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('listener must not be empty');

        new RedeliveryRequest(
            event: TestEventFactory::retrieveOrderPlaced(),
            listener: '',
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now(),
            lastError: new RuntimeException('boom'),
        );
    }

    public function test_rejects_whitespace_only_listener(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RedeliveryRequest(
            event: TestEventFactory::retrieveOrderPlaced(),
            listener: "   \t\n",
            attemptNumber: 1,
            nextRetryAt: CarbonImmutable::now(),
            lastError: new RuntimeException('boom'),
        );
    }
}
