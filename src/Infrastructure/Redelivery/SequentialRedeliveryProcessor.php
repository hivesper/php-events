<?php

namespace Vesper\Tool\Event\Infrastructure\Redelivery;

use Override;
use RuntimeException;
use Throwable;
use Vesper\Tool\Event\Dispatch\ListenerDispatcher;
use Vesper\Tool\Event\EventSubscriberMap;
use Vesper\Tool\Event\Infrastructure\Retry\NoRetryPolicy;
use Vesper\Tool\Event\ListenerKey;
use Vesper\Tool\Event\Redelivery\Redelivery;
use Vesper\Tool\Event\Redelivery\RedeliveryProcessor;
use Vesper\Tool\Event\Redelivery\RedeliveryStore;
use Vesper\Tool\Event\Retry\RetryPolicy;

readonly class SequentialRedeliveryProcessor implements RedeliveryProcessor
{
    /** @param EventSubscriberMap<object> $subscribers */
    public function __construct(
        private EventSubscriberMap $subscribers,
        private ListenerDispatcher $dispatcher,
        private RetryPolicy $retryPolicy = new NoRetryPolicy(),
    ) {}

    #[Override] public function process(RedeliveryStore $store): void
    {
        while ($redelivery = $store->next()) {
            if ($this->retryPolicy instanceof NoRetryPolicy) {
                $store->update($redelivery->markFailedPermanently(
                    new RuntimeException("No retry policy configured; redelivery for listener '$redelivery->listener' on event '{$redelivery->eventName()}' marked failed without dispatch."),
                ));

                continue;
            }

            $subscriber = $this->findRegisteredSubscriber($redelivery);

            if ($subscriber === null) {
                $store->update($redelivery->markFailedPermanently(
                    new RuntimeException("Listener '$redelivery->listener' is no longer registered for event '{$redelivery->eventName()}'."),
                ));

                continue;
            }

            try {
                $this->dispatcher->dispatch($redelivery->event, $subscriber);

                $store->update($redelivery->markSucceeded());
            } catch (Throwable $error) {
                $attemptsMade = $redelivery->attemptNumber + 1;
                $nextRetryAt = $this->retryPolicy->nextRetryAt($attemptsMade);

                if ($nextRetryAt === null) {
                    $store->update($redelivery->markFailedPermanently($error));

                    continue;
                }

                $store->update($redelivery->rescheduleTo($attemptsMade, $nextRetryAt, $error));
            }
        }
    }

    private function findRegisteredSubscriber(Redelivery $redelivery): callable|string|null
    {
        foreach ($this->subscribers->of($redelivery->eventName()) as $subscriber) {
            if ($redelivery->isFor(ListenerKey::of($subscriber))) {
                return $subscriber;
            }
        }

        return null;
    }
}
