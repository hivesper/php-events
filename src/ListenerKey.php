<?php

namespace Vesper\Tool\Event;

use Closure;

final class ListenerKey
{
    public static function of(callable|string $subscriber): string
    {
        if (is_string($subscriber)) {
            return $subscriber;
        }

        if (is_object($subscriber) && !($subscriber instanceof Closure)) {
            return $subscriber::class;
        }

        return 'Closure';
    }
}
