<?php

namespace Vesper\Tool\Event\Infrastructure\Dispatch;

use Override;
use Throwable;
use Vesper\Tool\Event\Dispatch\ListenerDispatcher;
use Vesper\Tool\Event\EventHydrator;
use Vesper\Tool\Event\HandlerResolver;
use Vesper\Tool\Event\Infrastructure\DefaultHandlerResolver;
use Vesper\Tool\Event\Infrastructure\JacksonHydrator;
use Vesper\Tool\Event\RawEvent;

readonly class DefaultListenerDispatcher implements ListenerDispatcher
{
    /** @param list<class-string<Throwable>> $ignoredExceptions */
    public function __construct(
        private HandlerResolver $resolver = new DefaultHandlerResolver(),
        private EventHydrator $hydrator = new JacksonHydrator(),
        private array $ignoredExceptions = [],
    ) {}

    #[Override] public function dispatch(RawEvent $event, callable|string $subscriber): void
    {
        $callable = $this->resolver->resolve($subscriber);
        $domainEvent = $this->hydrator->hydrate($event->name, $event->payload, $callable);

        try {
            $callable($domainEvent);
        } catch (Throwable $e) {
            if ($this->isIgnored($e)) {
                return;
            }

            throw $e;
        }
    }

    private function isIgnored(Throwable $error): bool
    {
        foreach ($this->ignoredExceptions as $class) {
            if ($error instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
