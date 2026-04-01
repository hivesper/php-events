<?php

namespace Vesper\Tool\Event;

interface HandlerResolver
{
    public function resolve(callable|string $subscriber): callable;
}
