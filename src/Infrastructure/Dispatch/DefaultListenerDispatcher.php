<?php

namespace Vesper\Tool\Event\Infrastructure\Dispatch;

use Override;
use ReflectionFunction;
use Throwable;
use Vesper\Tool\Event\Dispatch\ListenerDispatcher;
use Vesper\Tool\Event\EventContextFactory;
use Vesper\Tool\Event\EventHydrator;
use Vesper\Tool\Event\HandlerResolver;
use Vesper\Tool\Event\Infrastructure\DefaultEventContextFactory;
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
        private EventContextFactory $contextFactory = new DefaultEventContextFactory(),
    ) {}

    #[Override] public function dispatch(RawEvent $event, callable|string $subscriber): void
    {
        $callable = $this->resolver->resolve($subscriber);
        $arguments = [$this->hydrator->hydrate($event->name, $event->payload, $callable)];

        if ($this->acceptsContext($callable)) {
            $arguments[] = $this->contextFactory->create($event);
        }

        try {
            $callable(...$arguments);
        } catch (Throwable $e) {
            if ($this->isIgnored($e)) {
                return;
            }

            throw $e;
        }
    }

    /** Only listeners that declare a second parameter get the context, so one-argument listeners keep working. */
    private function acceptsContext(callable $callable): bool
    {
        return new ReflectionFunction($callable(...))->getNumberOfParameters() >= 2;
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
