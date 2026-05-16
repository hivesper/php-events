<?php

namespace Vesper\Tool\Event\Infrastructure;

use Override;
use Psr\Log\LoggerInterface;
use Throwable;
use Vesper\Tool\Event\EventProcessor;
use Vesper\Tool\Event\EventStore;

readonly class SilentEventProcessor implements EventProcessor
{
    public function __construct(
        private EventProcessor $inner,
        private LoggerInterface $logger,
    ) {}

    #[Override] public function process(EventStore $store): void
    {
        try {
            $this->inner->process($store);
        } catch (Throwable $e) {
            $this->logger->error('Event processor aborted.', ['exception' => $e]);
        }
    }
}
