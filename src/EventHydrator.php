<?php

namespace Tcds\Io\Ray;

interface EventHydrator
{
    /**
     * Reconstruct a domain event object from its stored event name and payload.
     *
     * Implement this in the consuming application to return typed domain event
     * objects. The processor calls this before dispatching to listeners, so
     * listeners never see RayEvent or raw arrays.
     *
     * @param array<string, mixed> $payload
     */
    public function hydrate(string $name, array $payload): object;
}
