<?php

namespace Vesper\Tool\Event\Infrastructure;

use Override;
use Vesper\Tool\Event\Dispatch\ListenerDispatcher;
use Vesper\Tool\Event\EventProcessor;
use Vesper\Tool\Event\EventStore;
use Vesper\Tool\Event\EventSubscriberMap;
use Vesper\Tool\Event\Infrastructure\Dispatch\DefaultListenerDispatcher;

readonly class SequentialEventProcessor implements EventProcessor
{
    /** @param EventSubscriberMap<object> $subscribers */
    public function __construct(
        private EventSubscriberMap $subscribers,
        private ListenerDispatcher $dispatcher = new DefaultListenerDispatcher(),
    ) {}

    #[Override] public function process(EventStore $store): void
    {
        while ($event = $store->next()) {
            foreach ($this->subscribers->of($event->name) as $subscriber) {
                $this->dispatcher->dispatch($event, $subscriber);
            }

            $event->markProcessed();
            $store->markProcessed($event);
        }
    }
}
