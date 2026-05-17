<?php

namespace Vesper\Tool\Event\Infrastructure\Dispatch;

use Override;
use Psr\Log\LoggerInterface;
use Throwable;
use Vesper\Tool\Event\Dispatch\ListenerDispatcher;
use Vesper\Tool\Event\ListenerKey;
use Vesper\Tool\Event\RawEvent;

/** Wrap outside DefaultListenerDispatcher so its $ignoredExceptions never reach the logger. */
readonly class LoggingListenerDispatcher implements ListenerDispatcher
{
    public function __construct(
        private ListenerDispatcher $inner,
        private LoggerInterface $logger,
    ) {}

    #[Override] public function dispatch(RawEvent $event, callable|string $subscriber): void
    {
        try {
            $this->inner->dispatch($event, $subscriber);
        } catch (Throwable $e) {
            $this->logger->error('Listener dispatch failed.', [
                'exception' => $e,
                'event' => $event->name,
                'listener' => ListenerKey::of($subscriber),
            ]);

            throw $e;
        }
    }
}
