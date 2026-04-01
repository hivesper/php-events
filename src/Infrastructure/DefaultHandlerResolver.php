<?php

namespace Vesper\Tool\Event\Infrastructure;

use RuntimeException;
use Vesper\Tool\Event\HandlerResolver;

class DefaultHandlerResolver implements HandlerResolver
{
    public function resolve(callable|string $subscriber): callable
    {
        if (is_callable($subscriber)) {
            return $subscriber;
        }

        $instance = new $subscriber();

        if (!is_callable($instance)) {
            throw new RuntimeException("Class '$subscriber' must implement __invoke().");
        }

        return $instance;
    }
}
