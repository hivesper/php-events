<?php

namespace Tcds\Io\Ray;

interface EventSerializer
{
    /**
     * Serialize a domain event into its stored name and payload.
     *
     * Implement this in the consuming application to map typed domain event
     * objects to the name + payload form stored in the outbox. The publisher
     * calls this before storing, so RayEvent never appears in the application
     * layer.
     */
    public function serialize(object $event): SerializedEvent;
}
