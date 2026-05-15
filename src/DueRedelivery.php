<?php

namespace Vesper\Tool\Event;

readonly class DueRedelivery
{
    /** @param string $listener class-string of the listener, or "Closure" for anonymous */
    public function __construct(
        public RawEvent $event,
        public string $listener,
        public int $attemptNumber,
    ) {
    }
}
