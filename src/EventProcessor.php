<?php

namespace Vesper\Tool\Event;

interface EventProcessor
{
    public function process(EventStore $store): void;
}
