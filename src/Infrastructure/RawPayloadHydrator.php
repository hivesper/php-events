<?php

namespace Tcds\Io\Ray\Infrastructure;

use Override;
use Tcds\Io\Ray\EventHydrator;

readonly class RawPayloadHydrator implements EventHydrator
{
    #[Override]
    public function hydrate(string $name, array $payload): object
    {
        return (object) $payload;
    }
}
