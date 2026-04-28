<?php

namespace Vesper\Tool\Event;

readonly class DueRedelivery
{
    /**
     * @param string $listener      class-string of the listener, or "Closure" for anonymous
     * @param int    $attemptNumber attempts already made; the upcoming retry will be (this + 1)
     */
    public function __construct(
        public RawEvent $event,
        public string $listener,
        public int $attemptNumber,
    ) {
    }
}
