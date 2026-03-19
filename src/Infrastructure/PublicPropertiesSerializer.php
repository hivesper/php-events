<?php

namespace Tcds\Io\Ray\Infrastructure;

use Override;
use ReflectionClass;
use Tcds\Io\Ray\EventSerializer;
use Tcds\Io\Ray\SerializedEvent;

readonly class PublicPropertiesSerializer implements EventSerializer
{
    /**
     * Derives the event name from the short class name as-is (PascalCase):
     *   OrderPlaced      → OrderPlaced
     *   PaymentReceived  → PaymentReceived
     *
     * WARNING: renaming the class silently changes the event name, which will
     * break any consumers subscribed to the old name. Use a custom EventSerializer
     * with explicit names when stability across deployments matters.
     *
     * Serializes all public properties as the payload. Nested objects are not
     * recursively serialized; use a custom EventSerializer for complex graphs.
     */
    #[Override]
    public function serialize(object $event): SerializedEvent
    {
        $name = new ReflectionClass($event)->getShortName();

        /** @var array<string, mixed> $payload */
        $payload = get_object_vars($event);

        return new SerializedEvent(name: $name, payload: $payload);
    }
}
