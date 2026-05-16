<?php

namespace Vesper\Tool\Event\Infrastructure\Redelivery;

use Override;
use Psr\Log\LoggerInterface;
use Throwable;
use Vesper\Tool\Event\Redelivery\RedeliveryProcessor;
use Vesper\Tool\Event\Redelivery\RedeliveryStore;

readonly class SilentRedeliveryProcessor implements RedeliveryProcessor
{
    public function __construct(
        private RedeliveryProcessor $inner,
        private LoggerInterface $logger,
    ) {}

    #[Override] public function process(RedeliveryStore $store): void
    {
        try {
            $this->inner->process($store);
        } catch (Throwable $e) {
            $this->logger->error('Redelivery processor aborted.', ['exception' => $e]);
        }
    }
}
