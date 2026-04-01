<?php

namespace Vesper\Tool\Event\Infrastructure;

use Override;
use Psr\Log\LoggerInterface;
use Vesper\Tool\Event\EventHydrator;
use Vesper\Tool\Event\EventSubscriberMap;
use Vesper\Tool\Event\HandlerResolver;
use Vesper\Tool\Event\RawEvent;
use Throwable;

readonly class SilentSequentialEventProcessor extends SequentialEventProcessor
{
    /**
     * @param EventSubscriberMap<object> $subscribers
     */
    public function __construct(
        EventSubscriberMap $subscribers,
        private LoggerInterface $logger,
        HandlerResolver $resolver = new DefaultHandlerResolver(),
        EventHydrator $hydrator = new JacksonHydrator(),
    ) {
        parent::__construct($subscribers, $resolver, $hydrator);
    }

    #[Override]
    protected function dispatch(RawEvent $event, callable|string $subscriber): void
    {
        try {
            parent::dispatch($event, $subscriber);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to dispatch event to listener.', [
                'event' => $event->name,
                'event_id' => $event->id,
                'listener' => is_string($subscriber) ? $subscriber : 'Closure',
                'exception' => $exception,
            ]);
        }
    }
}
