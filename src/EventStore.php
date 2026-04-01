<?php

namespace Vesper\Tool\Event;

interface EventStore
{
    public function add(RawEvent $event): void;

    public function next(): ?RawEvent;
}
