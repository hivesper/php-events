<?php

namespace Tcds\Io\Ray\Infrastructure;

use Override;
use Tcds\Io\Ray\EventHydrator;
use Tcds\Io\Ray\EventProcessor;
use Tcds\Io\Ray\EventStore;
use Tcds\Io\Ray\EventSubscriberMap;
use Tcds\Io\Ray\HandlerResolver;

readonly class SequentialEventProcessor implements EventProcessor
{
    /**
     * @param EventSubscriberMap<object> $subscribers
     */
    public function __construct(
        private EventSubscriberMap $subscribers,
        private HandlerResolver $resolver = new DefaultHandlerResolver(),
        private EventHydrator $hydrator = new RawPayloadHydrator(),
    ) {
    }

    #[Override] public function process(EventStore $store): void
    {
        while ($event = $store->next()) {
            foreach ($this->subscribers->of($event->name) as $subscriber) {
                $callable = $this->resolver->resolve($subscriber);

                $domainEvent = $this->hydrator->hydrate($event->name, $event->payload, $subscriber);

                $callable($domainEvent);
            }
        }
    }
}
