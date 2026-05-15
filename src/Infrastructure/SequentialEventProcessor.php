<?php

namespace Vesper\Tool\Event\Infrastructure;

use Closure;
use Override;
use RuntimeException;
use Throwable;
use Vesper\Tool\Event\EventHydrator;
use Vesper\Tool\Event\EventProcessor;
use Vesper\Tool\Event\EventStore;
use Vesper\Tool\Event\EventSubscriberMap;
use Vesper\Tool\Event\HandlerResolver;
use Vesper\Tool\Event\Infrastructure\Retry\NoRetryPolicy;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\RedeliveryRequest;
use Vesper\Tool\Event\RedeliveryTracker;
use Vesper\Tool\Event\Retry\RetryPolicy;

class SequentialEventProcessor implements EventProcessor
{
    /**
     * @param EventSubscriberMap<object>    $subscribers
     * @param list<class-string<Throwable>> $ignoredExceptions
     */
    public function __construct(
        private readonly EventSubscriberMap $subscribers,
        private readonly HandlerResolver $resolver = new DefaultHandlerResolver(),
        private readonly EventHydrator $hydrator = new JacksonHydrator(),
        private readonly RetryPolicy $retryPolicy = new NoRetryPolicy(),
        private readonly ?RedeliveryTracker $redeliveryTracker = null,
        private readonly array $ignoredExceptions = [],
    ) {
    }

    #[Override] public function process(EventStore $store): void
    {
        while ($event = $store->next()) {
            foreach ($this->subscribers->of($event->name) as $subscriber) {
                $this->dispatch($event, $subscriber);
            }
            $store->markProcessed($event->id);
        }
    }

    /**
     * Dispatch the next due redelivery, if any. Call this from a separate
     * scheduled job so persisted retries are picked up independently of
     * the main outbox worker.
     *
     * The tracker holds the SELECT ... FOR UPDATE row lock through dispatch
     * via processNextDue(), so concurrent cron workers cannot pick up the
     * same row. Dispatch's fail-fast throw is deferred until after the
     * transaction commits so the schedule / markFailedPermanently side
     * effects persist.
     */
    public function processNextRedelivery(): void
    {
        if ($this->redeliveryTracker === null) {
            return;
        }

        $deferredException = null;

        $this->redeliveryTracker->processNextDue(function ($due) use (&$deferredException): void {
            $subscriber = $this->findRegisteredSubscriber($due->event->name, $due->listener);

            if ($subscriber === null) {
                $this->redeliveryTracker->markFailedPermanently(
                    $due->event->id,
                    $due->listener,
                    new RuntimeException("Listener '{$due->listener}' is no longer registered for event '{$due->event->name}'."),
                );
                return;
            }

            try {
                $this->dispatch($due->event, $subscriber, $due->attemptNumber);
            } catch (Throwable $e) {
                $deferredException = $e;
            }
        });

        if ($deferredException !== null) {
            throw $deferredException;
        }
    }

    private function findRegisteredSubscriber(string $eventName, string $listenerKey): callable|string|null
    {
        foreach ($this->subscribers->of($eventName) as $subscriber) {
            if ($this->listenerKey($subscriber) === $listenerKey) {
                return $subscriber;
            }
        }
        return null;
    }

    /** @param int $attemptsMade attempts already made before this call (0 for fresh, ≥1 from redelivery) */
    protected function dispatch(RawEvent $event, callable|string $subscriber, int $attemptsMade = 0): void
    {
        $callable = $this->resolver->resolve($subscriber);
        $domainEvent = $this->hydrator->hydrate($event->name, $event->payload, $callable);
        $listener = $this->listenerKey($subscriber);

        try {
            $callable($domainEvent);
            $this->redeliveryTracker?->markSucceeded($event->id, $listener);
        } catch (Throwable $e) {
            if ($this->isIgnored($e)) {
                return;
            }

            $attemptsMade++;
            $nextRetryAt = $this->retryPolicy->nextRetryAt($attemptsMade);

            if ($nextRetryAt === null) {
                $this->onPermanentFailure($event, $subscriber, $e);
                throw $e;
            }

            if ($this->redeliveryTracker === null) {
                throw $e;
            }

            $this->redeliveryTracker->schedule(new RedeliveryRequest(
                event: $event,
                listener: $listener,
                attemptNumber: $attemptsMade,
                nextRetryAt: $nextRetryAt,
                lastError: $e,
            ));
        }
    }

    /** Called when a listener's failure can no longer be retried. Subclasses can extend (e.g. log). */
    protected function onPermanentFailure(RawEvent $event, callable|string $subscriber, Throwable $error): void
    {
        $this->redeliveryTracker?->markFailedPermanently($event->id, $this->listenerKey($subscriber), $error);
    }

    protected function listenerKey(callable|string $subscriber): string
    {
        if (is_string($subscriber)) {
            return $subscriber;
        }

        if (is_object($subscriber) && !($subscriber instanceof Closure)) {
            return $subscriber::class;
        }

        return 'Closure';
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
