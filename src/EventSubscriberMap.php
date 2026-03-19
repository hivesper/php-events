<?php

namespace Tcds\Io\Ray;

/**
 * @template TEvent of object
 * @phpstan-type Subscriber = callable(TEvent $event): void | class-string
 */
class EventSubscriberMap
{
    /**
     * @param array<string, list<Subscriber>> $subscribers
     */
    public function __construct(private array $subscribers = [])
    {
    }

    /**
     * @param string $name The event name to subscribe to.
     * @param Subscriber $subscriber A subscriber to be called when an event with the given name is dispatched.
     */
    public function subscribe(string $name, callable|string $subscriber): void
    {
        $this->subscribers[$name][] = $subscriber;
    }

    /**
     * @param EventSubscriberMap<TEvent> $other
     */
    public function merge(EventSubscriberMap $other): void
    {
        foreach ($other->subscribers as $name => $subscribers) {
            foreach ($subscribers as $subscriber) {
                $this->subscribe($name, $subscriber);
            }
        }
    }

    /**
     * @return list<Subscriber>
     */
    public function of(string $name): array
    {
        return $this->subscribers[$name] ?? [];
    }
}
