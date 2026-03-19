<?php

namespace Test\Tcds\Io\Ray\Unit;

use PHPUnit\Framework\TestCase;
use Tcds\Io\Ray\EventSubscriberMap;
use Tcds\Io\Ray\RayEvent;
use Test\Tcds\Io\Ray\_Fixtures\ListenerA;
use Test\Tcds\Io\Ray\_Fixtures\ListenerB;
use Test\Tcds\Io\Ray\_Fixtures\TestEventFactory;

class EventSubscriberMapTest extends TestCase
{
    private EventSubscriberMap $subscribers;

    protected function setUp(): void
    {
        $this->subscribers = new EventSubscriberMap();
    }

    public function test_of_returns_empty_array_for_unknown_event_type(): void
    {
        self::assertSame([], $this->subscribers->of('unknown.event'));
    }

    public function test_subscribe_registers_a_handler(): void
    {
        $called = false;
        $this->subscribers->subscribe('order.placed', function () use (&$called) {
            $called = true;
        });

        $handlers = $this->subscribers->of('order.placed');

        self::assertCount(1, $handlers);
        ($handlers[0])(TestEventFactory::retrieveOrderPlaced());
        self::assertTrue($called);
    }

    public function test_subscribe_supports_multiple_handlers_for_the_same_type(): void
    {
        $log = [];
        $this->subscribers->subscribe('order.placed', function () use (&$log) {
            $log[] = 'first';
        });
        $this->subscribers->subscribe('order.placed', function () use (&$log) {
            $log[] = 'second';
        });

        $handlers = $this->subscribers->of('order.placed');

        self::assertCount(2, $handlers);

        foreach ($handlers as $handler) {
            $handler(TestEventFactory::retrieveOrderPlaced());
        }
        self::assertSame(['first', 'second'], $log);
    }

    public function test_subscribe_isolates_handlers_per_event_type(): void
    {
        $this->subscribers->subscribe('order.placed', fn() => null);
        $this->subscribers->subscribe('payment.failed', fn() => null);

        self::assertCount(1, $this->subscribers->of('order.placed'));
        self::assertCount(1, $this->subscribers->of('payment.failed'));
    }

    public function test_constructor_accepts_pre_populated_subscribers(): void
    {
        $called = false;
        $subscriber = new EventSubscriberMap([
            'order.placed' => [function () use (&$called) {
                $called = true;
            }],
        ]);

        ($subscriber->of('order.placed')[0])(TestEventFactory::retrieveOrderPlaced());

        self::assertTrue($called);
    }

    public function test_subscribe_passes_event_to_handler(): void
    {
        $received = null;
        $this->subscribers->subscribe('order.placed', function (RayEvent $e) use (&$received) {
            $received = $e;
        });

        $event = TestEventFactory::retrieveOrderPlaced();
        ($this->subscribers->of('order.placed')[0])($event);

        self::assertSame($event, $received);
    }

    public function test_merge_combines_subscribers_from_another_map(): void
    {
        $mapA = new EventSubscriberMap([
            'order.placed' => [ListenerA::class],
        ]);
        $mapB = new EventSubscriberMap([
            'order.placed' => [ListenerB::class],
            'payment.failed' => [ListenerB::class],
        ]);

        $mapA->merge($mapB);

        self::assertSame([ListenerA::class, ListenerB::class], $mapA->of('order.placed'));
        self::assertSame([ListenerB::class], $mapA->of('payment.failed'));
    }

    public function test_merge_into_empty_map_copies_all_subscribers(): void
    {
        $mapA = new EventSubscriberMap();
        $mapB = new EventSubscriberMap([
            'order.placed' => [ListenerA::class],
        ]);

        $mapA->merge($mapB);

        self::assertSame([ListenerA::class], $mapA->of('order.placed'));
    }

    public function test_merge_with_empty_map_leaves_target_unchanged(): void
    {
        $mapA = new EventSubscriberMap([
            'order.placed' => [ListenerA::class],
        ]);

        $mapA->merge(new EventSubscriberMap());

        self::assertSame([ListenerA::class], $mapA->of('order.placed'));
    }

    public function test_merge_does_not_mutate_the_source_map(): void
    {
        $mapA = new EventSubscriberMap();
        $mapB = new EventSubscriberMap([
            'order.placed' => [ListenerA::class],
        ]);

        $mapA->merge($mapB);

        self::assertSame([ListenerA::class], $mapB->of('order.placed'));
    }
}
