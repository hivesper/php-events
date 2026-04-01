<?php

namespace Vesper\Tool\Event\Infrastructure;

use Override;
use ReflectionClass;
use Tcds\Io\Jackson\ArrayObjectMapper;
use Vesper\Tool\Event\EventSerializer;
use Vesper\Tool\Event\SerializedEvent;

readonly class JacksonSerializer implements EventSerializer
{
    private ArrayObjectMapper $mapper;

    public function __construct()
    {
        $this->mapper = new ArrayObjectMapper();
    }

    #[Override]
    public function serialize(object $event): SerializedEvent
    {
        $name = new ReflectionClass($event)->getShortName();

        /** @var array<string, mixed> $payload */
        $payload = $this->mapper->writeValue($event);

        return new SerializedEvent(name: $name, payload: $payload);
    }
}
