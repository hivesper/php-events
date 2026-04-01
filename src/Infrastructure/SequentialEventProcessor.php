<?php

namespace Vesper\Tool\Event\Infrastructure;

use Override;
use Vesper\Tool\Event\EventHydrator;
use Vesper\Tool\Event\EventProcessor;
use Vesper\Tool\Event\EventStore;
use Vesper\Tool\Event\EventSubscriberMap;
use Vesper\Tool\Event\HandlerResolver;
use Vesper\Tool\Event\RawEvent;

readonly class SequentialEventProcessor implements EventProcessor
{
    /**
     * @param EventSubscriberMap<object> $subscribers
     */
    public function __construct(
        private EventSubscriberMap $subscribers,
        private HandlerResolver $resolver = new DefaultHandlerResolver(),
        private EventHydrator $hydrator = new JacksonHydrator(),
    ) {
    }

    #[Override] public function process(EventStore $store): void
    {
        while ($event = $store->next()) {
            foreach ($this->subscribers->of($event->name) as $subscriber) {
                $this->dispatch($event, $subscriber);
            }
        }
    }

    protected function dispatch(RawEvent $event, callable|string $subscriber): void
    {
        $callable = $this->resolver->resolve($subscriber);

        $domainEvent = $this->hydrator->hydrate($event->name, $event->payload, $callable);

        $callable($domainEvent);
    }
}
