<?php

namespace Test\Vesper\Tool\Event\_Fixtures;

use Vesper\Tool\Event\Infrastructure\SequentialEventProcessor;

/**
 * Test-only subclass of SequentialEventProcessor that records sleep durations
 * instead of actually sleeping. Lets retry tests assert in-process retry timing
 * without slowing the suite.
 */
class RecordingSequentialEventProcessor extends SequentialEventProcessor
{
    /** @var list<int> */
    public array $sleeps = [];

    protected function sleep(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
    }
}
