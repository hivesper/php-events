<?php

namespace Tcds\Io\Ray;

interface HandlerResolver
{
    public function resolve(callable|string $subscriber): callable;
}
