<?php

namespace Test\Vesper\Tool\Event\Unit\Dispatch;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Test\Vesper\Tool\Event\_Fixtures\TestEventFactory;
use Test\Vesper\Tool\Event\_Fixtures\ThrowingListener;
use Throwable;
use Vesper\Tool\Event\Dispatch\ListenerDispatcher;
use Vesper\Tool\Event\Infrastructure\Dispatch\LoggingListenerDispatcher;
use Vesper\Tool\Event\RawEvent;

class LoggingListenerDispatcherTest extends TestCase
{
    public function test_passes_through_without_logging_when_inner_returns_cleanly(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $dispatcher = new LoggingListenerDispatcher(self::passthroughDispatcher(), $logger);

        $dispatcher->dispatch($event, function () {});
    }

    public function test_logs_and_rethrows_when_inner_throws(): void
    {
        $event = TestEventFactory::retrieveOrderPlaced();
        $exception = new RuntimeException('boom');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Listener dispatch failed.',
                [
                    'exception' => $exception,
                    'event' => $event->name,
                    'listener' => ThrowingListener::class,
                ],
            );

        $dispatcher = new LoggingListenerDispatcher(self::throwingDispatcher($exception), $logger);

        $this->expectExceptionObject($exception);

        $dispatcher->dispatch($event, ThrowingListener::class);
    }

    private static function passthroughDispatcher(): ListenerDispatcher
    {
        return new readonly class implements ListenerDispatcher {
            public function dispatch(RawEvent $event, callable|string $subscriber): void {}
        };
    }

    private static function throwingDispatcher(Throwable $error): ListenerDispatcher
    {
        return new readonly class ($error) implements ListenerDispatcher {
            public function __construct(private Throwable $error) {}
            public function dispatch(RawEvent $event, callable|string $subscriber): void
            {
                throw $this->error;
            }
        };
    }
}
