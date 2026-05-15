<?php

namespace Test\Vesper\Tool\Event\Unit;

use PHPUnit\Framework\TestCase;
use Vesper\Tool\Event\RawEventStatus;

class RayEventStatusTest extends TestCase
{
    public function test_has_pending_case(): void
    {
        self::assertSame('pending', RawEventStatus::pending->value);
    }

    public function test_has_processing_case(): void
    {
        self::assertSame('processing', RawEventStatus::processing->value);
    }

    public function test_has_processed_case(): void
    {
        self::assertSame('processed', RawEventStatus::processed->value);
    }

    public function test_from_resolves_pending(): void
    {
        self::assertSame(RawEventStatus::pending, RawEventStatus::from('pending'));
    }

    public function test_from_resolves_processing(): void
    {
        self::assertSame(RawEventStatus::processing, RawEventStatus::from('processing'));
    }

    public function test_from_resolves_processed(): void
    {
        self::assertSame(RawEventStatus::processed, RawEventStatus::from('processed'));
    }
}
