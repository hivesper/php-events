<?php

namespace Test\Tcds\Io\Ray\_Fixtures;

class TrackingListener
{
    private ?object $lastReceived = null;

    public function __invoke(object $event): void
    {
        $this->lastReceived = $event;
    }

    public function received(): ?object
    {
        return $this->lastReceived;
    }
}
