<?php

namespace Test\Tcds\Io\Ray\Unit;

use PHPUnit\Framework\TestCase;
use Tcds\Io\Ray\EventSubscriberBuilder;
use Test\Tcds\Io\Ray\_Fixtures\ListenerA;
use Test\Tcds\Io\Ray\_Fixtures\ListenerB;

class EventSubscriberBuilderTest extends TestCase
{
    public function test_build_returns_empty_array_with_no_registrations(): void
    {
        $result = EventSubscriberBuilder::create()->build();

        self::assertSame([], $result->of('some.event'));
    }

    public function test_eventName_registers_callable_as_listener(): void
    {
        $listener = fn () => null;

        $result = EventSubscriberBuilder::create()
            ->eventName('order.placed', [$listener])
            ->build();

        self::assertEquals([$listener], $result->of('order.placed'));
    }

    public function test_eventName_registers_multiple_listeners(): void
    {
        $result = EventSubscriberBuilder::create()
            ->eventName('order.placed', [ListenerA::class, ListenerB::class])
            ->build();

        self::assertSame([ListenerA::class, ListenerB::class], $result->of('order.placed'));
    }

    public function test_multiple_calls_merge_listeners_for_the_same_names(): void
    {
        $result = EventSubscriberBuilder::create()
            ->eventName('order.placed', [ListenerA::class])
            ->eventName('order.placed', [ListenerB::class])
            ->build();

        self::assertEqualsCanonicalizing(
            [ListenerA::class, ListenerB::class],
            $result->of('order.placed'),
        );
    }

    public function test_listener_registers_one_listener_for_multiple_names(): void
    {
        $result = EventSubscriberBuilder::create()
            ->listener(ListenerA::class, names: ['order.placed', 'order.cancelled'])
            ->build();

        self::assertSame([ListenerA::class], $result->of('order.placed'));
        self::assertSame([ListenerA::class], $result->of('order.cancelled'));
    }

    public function test_duplicate_listeners_are_deduplicated(): void
    {
        $result = EventSubscriberBuilder::create()
            ->eventName('order.placed', [ListenerA::class])
            ->eventName('order.placed', [ListenerA::class])
            ->build();

        self::assertSame([ListenerA::class], $result->of('order.placed'));
    }

    public function test_duplicate_of_multiple_function_calls_are_deduplicated(): void
    {
        $result = EventSubscriberBuilder::create()
            ->eventName('order.placed', [ListenerA::class, ListenerB::class])
            ->listener(ListenerA::class, names: ['order.placed'])
            ->listener(ListenerB::class, names: ['order.placed'])
            ->build();

        self::assertSame([ListenerA::class, ListenerB::class], $result->of('order.placed'));
    }

    public function test_duplicate_callable_listeners_are_deduplicated(): void
    {
        $listener = fn () => null;

        $result = EventSubscriberBuilder::create()
            ->eventName('order.placed', [$listener])
            ->eventName('order.placed', [$listener])
            ->build();

        self::assertCount(1, $result->of('order.placed'));
    }

    public function test_distinct_callable_listeners_are_not_deduplicated(): void
    {
        $a = fn () => null;
        $b = fn () => null;

        $result = EventSubscriberBuilder::create()
            ->eventName('order.placed', [$a])
            ->eventName('order.placed', [$b])
            ->build();

        self::assertCount(2, $result->of('order.placed'));
    }

    public function test_create_returns_a_new_builder_instance(): void
    {
        $a = EventSubscriberBuilder::create();
        $b = EventSubscriberBuilder::create();

        self::assertNotSame($a, $b);
    }
}
