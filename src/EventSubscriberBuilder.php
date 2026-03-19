<?php

namespace Tcds\Io\Ray;

/**
 * @template TEvent of object
 * @phpstan-type Subscriber = callable(TEvent $event): void | class-string
 */
class EventSubscriberBuilder
{
    /**
     * @var array<string, list<Subscriber>>
     */
    private array $events = [];

    /**
     * @return self<TEvent>
     */
    public static function create(): self
    {
        /** @var self<TEvent> */
        return new self();
    }

    /**
     * @param string $name Plain, non-class event name.
     * @param list<Subscriber> $listeners List of listeners to register for the event.
     *
     * @return self<TEvent>
     */
    public function eventName(string $name, array $listeners): self
    {
        foreach ($listeners as $listener) {
            if (!in_array($listener, $this->events[$name] ?? [], true)) {
                $this->events[$name][] = $listener;
            }
        }

        return $this;
    }

    /**
     * @param Subscriber $listener Listener class to register for the events.
     * @param list<string> $names Plain, non-class event names to register the listener for.
     *
     * @return self<TEvent>
     */
    public function listener(callable|string $listener, array $names = []): self
    {
        /** @var list<Subscriber> $single */
        $single = [$listener];

        foreach ($names as $name) {
            $this->eventName($name, $single);
        }

        return $this;
    }

    /**
     * @return EventSubscriberMap<TEvent>
     */
    public function build(): EventSubscriberMap
    {
        /** @var EventSubscriberMap<TEvent> */
        return new EventSubscriberMap($this->events);
    }
}
