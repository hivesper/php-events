<?php

namespace Vesper\Tool\Event\Dispatch;

use Throwable;
use Vesper\Tool\Event\RawEvent;

interface ListenerDispatcher
{
    /** @throws Throwable when the listener itself throws an unignored exception. */
    public function dispatch(RawEvent $event, callable|string $subscriber): void;
}
