<?php

namespace Test\Vesper\Tool\Event\Unit\Redelivery;

use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Vesper\Tool\Event\Infrastructure\Redelivery\InMemoryRedeliveryStore;
use Vesper\Tool\Event\Infrastructure\Redelivery\SilentRedeliveryProcessor;
use Vesper\Tool\Event\Redelivery\RedeliveryProcessor;
use Vesper\Tool\Event\Redelivery\RedeliveryStore;

class SilentRedeliveryProcessorTest extends TestCase
{
    public function test_delegates_to_inner_when_no_exception_thrown(): void
    {
        $inner = new class implements RedeliveryProcessor {
            public bool $called = false;
            #[Override] public function process(RedeliveryStore $store): void
            {
                $this->called = true;
            }
        };
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        new SilentRedeliveryProcessor($inner, $logger)->process(new InMemoryRedeliveryStore());

        self::assertTrue($inner->called);
    }

    public function test_catches_and_logs_exception_from_inner(): void
    {
        $exception = new RuntimeException('redelivery processor exploded');
        $inner = new class ($exception) implements RedeliveryProcessor {
            public function __construct(private RuntimeException $error) {}
            #[Override] public function process(RedeliveryStore $store): void
            {
                throw $this->error;
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Redelivery processor aborted.',
                ['exception' => $exception],
            );

        new SilentRedeliveryProcessor($inner, $logger)->process(new InMemoryRedeliveryStore());
    }
}
