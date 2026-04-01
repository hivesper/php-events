<?php

namespace Vesper\Tool\Event;

readonly class SerializedEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $name,
        public array $payload,
    ) {
    }
}
