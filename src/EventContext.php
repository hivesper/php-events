<?php

namespace Vesper\Tool\Event;

use Carbon\CarbonImmutable;

/**
 * What a listener can know about the stored event it is handling. The id stays the same across
 * redeliveries, so a listener can use it to skip work it already did.
 */
final readonly class EventContext
{
    public function __construct(
        public string $eventId,
        public string $eventName,
        public CarbonImmutable $createdAt,
    ) {}
}
