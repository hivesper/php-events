<?php

namespace Tcds\Io\Ray;

/**
 * @phpstan-import-type Subscriber from EventSubscriberMap
 *
 * @template TEvent of object
 */
class EventSubscriberBuilder
{
    /**
     * @var array<non-empty-string, list<Subscriber>>
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
     * @param non-empty-string $name Plain, non-class event name.
     * @param list<Subscriber> $listeners List of listener classes to register for the event.
     *
     * @return self<TEvent>
     */
    public function eventName(string $name, array $listeners): self
    {
        $this->events[$name] = array_merge($this->events[$name] ?? [], array_flip($listeners));

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
        array_map(fn(string $name) => $this->eventName($name, [$listener]), $names);

        return $this;
    }

    /**
     * @return EventSubscriberMap<TEvent>
     */
    public function build(): EventSubscriberMap
    {
        /** @var EventSubscriberMap<TEvent> */
        return new EventSubscriberMap(
            array_map(fn(array $listeners) => array_keys($listeners), $this->events),
        );
    }
}
