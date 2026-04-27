<?php

namespace Vesper\Tool\Event\Infrastructure;

use Override;
use Psr\Log\LoggerInterface;
use Throwable;
use Vesper\Tool\Event\EventHydrator;
use Vesper\Tool\Event\EventSubscriberMap;
use Vesper\Tool\Event\HandlerResolver;
use Vesper\Tool\Event\Infrastructure\Retry\NoRetryPolicy;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\RedeliveryTracker;
use Vesper\Tool\Event\Retry\RetryPolicy;

class SilentSequentialEventProcessor extends SequentialEventProcessor
{
    /**
     * @param EventSubscriberMap<object>    $subscribers
     * @param list<class-string<Throwable>> $ignoredExceptions
     */
    public function __construct(
        EventSubscriberMap $subscribers,
        private readonly LoggerInterface $logger,
        HandlerResolver $resolver = new DefaultHandlerResolver(),
        EventHydrator $hydrator = new JacksonHydrator(),
        RetryPolicy $retryPolicy = new NoRetryPolicy(),
        ?RedeliveryTracker $redeliveryTracker = null,
        array $ignoredExceptions = [],
        int $inProcessRetryThresholdMs = 1000,
    ) {
        parent::__construct(
            subscribers: $subscribers,
            resolver: $resolver,
            hydrator: $hydrator,
            retryPolicy: $retryPolicy,
            redeliveryTracker: $redeliveryTracker,
            ignoredExceptions: $ignoredExceptions,
            inProcessRetryThresholdMs: $inProcessRetryThresholdMs,
        );
    }

    #[Override]
    protected function dispatch(RawEvent $event, callable|string $subscriber, int $attemptsMade = 0): void
    {
        try {
            parent::dispatch($event, $subscriber, $attemptsMade);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to dispatch event to listener.', [
                'event' => $event->name,
                'event_id' => $event->id,
                'listener' => $this->listenerKey($subscriber),
                'exception' => $exception,
            ]);
        }
    }
}
