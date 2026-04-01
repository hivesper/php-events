<?php

namespace Vesper\Tool\Event;

use Carbon\CarbonImmutable;
use Vesper\Tool\Event\Infrastructure\JacksonSerializer;

readonly class EventPublisher
{
    public function __construct(
        private EventStore $store,
        private EventSerializer $serializer = new JacksonSerializer(),
    ) {
    }

    public function publish(object $event, ?CarbonImmutable $publishAt = null): string
    {
        $serialized = $this->serializer->serialize($event);

        $raw = RawEvent::create(
            name: $serialized->name,
            payload: $serialized->payload,
            publishAt: $publishAt,
        );

        $this->store->add($raw);

        return $raw->id;
    }
}
