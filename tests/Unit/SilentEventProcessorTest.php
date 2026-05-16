<?php

namespace Test\Vesper\Tool\Event\Unit;

use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Vesper\Tool\Event\EventProcessor;
use Vesper\Tool\Event\EventStore;
use Vesper\Tool\Event\Infrastructure\InMemoryEventStore;
use Vesper\Tool\Event\Infrastructure\SilentEventProcessor;

class SilentEventProcessorTest extends TestCase
{
    public function test_delegates_to_inner_when_no_exception_thrown(): void
    {
        $inner = new class implements EventProcessor {
            public bool $called = false;
            #[Override] public function process(EventStore $store): void
            {
                $this->called = true;
            }
        };
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        new SilentEventProcessor($inner, $logger)->process(new InMemoryEventStore());

        self::assertTrue($inner->called);
    }

    public function test_catches_and_logs_exception_from_inner(): void
    {
        $exception = new RuntimeException('processor exploded');
        $inner = new class ($exception) implements EventProcessor {
            public function __construct(private RuntimeException $error) {}
            #[Override] public function process(EventStore $store): void
            {
                throw $this->error;
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Event processor aborted.',
                ['exception' => $exception],
            );

        new SilentEventProcessor($inner, $logger)->process(new InMemoryEventStore());
    }
}
