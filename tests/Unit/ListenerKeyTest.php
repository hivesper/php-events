<?php

namespace Test\Vesper\Tool\Event\Unit;

use PHPUnit\Framework\TestCase;
use Test\Vesper\Tool\Event\_Fixtures\ListenerA;
use Vesper\Tool\Event\ListenerKey;

class ListenerKeyTest extends TestCase
{
    public function test_string_subscriber_returns_itself(): void
    {
        self::assertSame('App\\Listener', ListenerKey::of('App\\Listener'));
    }

    public function test_object_subscriber_returns_its_class(): void
    {
        self::assertSame(ListenerA::class, ListenerKey::of(new ListenerA()));
    }

    public function test_closure_subscriber_returns_the_literal_closure(): void
    {
        $closure = fn() => null;

        self::assertSame('Closure', ListenerKey::of($closure));
    }
}
