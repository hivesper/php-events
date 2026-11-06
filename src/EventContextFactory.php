<?php

namespace Vesper\Tool\Event;

interface EventContextFactory
{
    /**
     * Build the second argument handed to a listener that declares one. Implement this to give
     * listeners an application-owned context class instead of EventContext.
     */
    public function create(RawEvent $event): object;
}
