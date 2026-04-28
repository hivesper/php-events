<?php

namespace Vesper\Tool\Event\Infrastructure;

use Carbon\CarbonImmutable;
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
use Vesper\Tool\Event\RedeliveryTracker;
use Vesper\Tool\Event\Retry\RetryPolicy;

class SequentialEventProcessor implements EventProcessor
{
    /**
     * @param EventSubscriberMap<object>    $subscribers
     * @param list<class-string<Throwable>> $ignoredExceptions         exceptions thrown by a
     *                                                                 listener that should be
     *                                                                 silently swallowed: no
     *                                                                 retry, no log, no redelivery
     *                                                                 row
     * @param int                           $inProcessRetryThresholdMs the policy's next-retry
     *                                                                 delay is performed in-process
     *                                                                 (sleep + retry) when ≤ this
     *                                                                 value; otherwise the failure
     *                                                                 is persisted for later
     *                                                                 redelivery
     */
    public function __construct(
        private readonly EventSubscriberMap $subscribers,
        private readonly HandlerResolver $resolver = new DefaultHandlerResolver(),
        private readonly EventHydrator $hydrator = new JacksonHydrator(),
        private readonly RetryPolicy $retryPolicy = new NoRetryPolicy(),
        private readonly ?RedeliveryTracker $redeliveryTracker = null,
        private readonly array $ignoredExceptions = [],
        private readonly int $inProcessRetryThresholdMs = 1000,
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

        if ($this->redeliveryTracker !== null) {
            while ($due = $this->redeliveryTracker->nextDue()) {
                $subscriber = $this->findRegisteredSubscriber($due->event->name, $due->listener);

                if ($subscriber === null) {
                    $this->redeliveryTracker->markFailedPermanently(
                        $due->event->id,
                        $due->listener,
                        new RuntimeException("Listener '{$due->listener}' is no longer registered for event '{$due->event->name}'."),
                    );
                    continue;
                }

                $this->dispatch($due->event, $subscriber, $due->attemptNumber);
            }
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

    /**
     * @param int $attemptsMade attempts already made before this dispatch call (0 for a fresh
     *                          event, ≥1 when called from the redelivery drain)
     */
    protected function dispatch(RawEvent $event, callable|string $subscriber, int $attemptsMade = 0): void
    {
        $callable = $this->resolver->resolve($subscriber);
        $domainEvent = $this->hydrator->hydrate($event->name, $event->payload, $callable);
        $listener = $this->listenerKey($subscriber);

        while (true) {
            try {
                $callable($domainEvent);
                $this->redeliveryTracker?->markSucceeded($event->id, $listener);
                return;
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

                $delayMs = $this->msUntil($nextRetryAt);

                if ($delayMs <= $this->inProcessRetryThresholdMs) {
                    $this->sleep($delayMs);
                    continue;
                }

                if ($this->redeliveryTracker !== null) {
                    $this->redeliveryTracker->schedule($event, $listener, $attemptsMade, $nextRetryAt, $e);
                    return;
                }

                $this->onPermanentFailure($event, $subscriber, $e);
                throw $e;
            }
        }
    }

    /**
     * Hook called once a listener's failure can no longer be retried (policy exhausted, or no
     * tracker is configured to persist a long-delay retry). Default: persist the permanent-failure
     * marker on the redelivery tracker if one is configured. Subclasses can extend (e.g. log).
     */
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

    protected function sleep(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }
        usleep($milliseconds * 1000);
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

    private function msUntil(CarbonImmutable $when): int
    {
        $diffMs = (int) round(CarbonImmutable::now()->diffInMilliseconds($when, absolute: false));
        return max(0, $diffMs);
    }
}
