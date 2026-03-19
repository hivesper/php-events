<?php

namespace Test\Tcds\Io\Ray\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Tcds\Io\Ray\Infrastructure\DefaultHandlerResolver;
use Test\Tcds\Io\Ray\_Fixtures\TrackingListener;

class DefaultHandlerResolverTest extends TestCase
{
    private DefaultHandlerResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DefaultHandlerResolver();
    }

    public function test_returns_callable_directly(): void
    {
        $callable = fn () => null;

        $resolved = $this->resolver->resolve($callable);

        self::assertSame($callable, $resolved);
    }

    public function test_instantiates_class_string_and_returns_invocable(): void
    {
        $payload = (object) ['order_id' => 1];
        $resolved = $this->resolver->resolve(TrackingListener::class);

        $resolved($payload);

        self::assertInstanceOf(TrackingListener::class, $resolved);
        self::assertSame($payload, $resolved->received());
    }

    public function test_throws_when_class_does_not_implement_invoke(): void
    {
        $this->expectException(RuntimeException::class);

        $this->resolver->resolve(stdClass::class);
    }
}
