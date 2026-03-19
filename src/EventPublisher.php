<?php

namespace Tcds\Io\Ray;

use Carbon\CarbonImmutable;

readonly class EventPublisher
{
    public function __construct(
        private EventStore $store,
        private EventSerializer $serializer,
    ) {
    }

    public function publish(object $event, ?CarbonImmutable $publishAt = null): string
    {
        $serialized = $this->serializer->serialize($event);

        $rayEvent = RayEvent::create(
            name: $serialized->name,
            payload: $serialized->payload,
            publishAt: $publishAt,
        );

        $this->store->add($rayEvent);

        return $rayEvent->id;
    }
}
