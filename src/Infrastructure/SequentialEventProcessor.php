<?php

namespace Tcds\Io\Ray\Infrastructure;

use Override;
use Tcds\Io\Ray\EventProcessor;
use Tcds\Io\Ray\EventStore;
use Tcds\Io\Ray\EventSubscriberMap;

readonly class SequentialEventProcessor implements EventProcessor
{
    public function __construct(private EventSubscriberMap $subscribers)
    {
    }

    /**
     * @param EventStore<object> $store
     */
    #[Override] public function process(EventStore $store): void
    {
        while ($event = $store->next()) {
            foreach ($this->subscribers->of($event->type) as $subscriber) {
                $subscriber($event);
            }
        }
    }
}
