<?php

namespace Vesper\Tool\Event\Infrastructure\Dispatch;

use Carbon\CarbonImmutable;
use Override;
use Throwable;
use Vesper\Tool\Event\Dispatch\ListenerDispatcher;
use Vesper\Tool\Event\ListenerKey;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\Redelivery\Redelivery;
use Vesper\Tool\Event\Redelivery\RedeliveryStore;

readonly class RedeliveringListenerDispatcher implements ListenerDispatcher
{
    public function __construct(
        private ListenerDispatcher $inner,
        private RedeliveryStore $store,
    ) {}

    #[Override] public function dispatch(RawEvent $event, callable|string $subscriber): void
    {
        try {
            $this->inner->dispatch($event, $subscriber);
        } catch (Throwable $e) {
            $this->store->schedule(Redelivery::schedule(
                event: $event,
                listener: ListenerKey::of($subscriber),
                attemptNumber: 1,
                nextRetryAt: CarbonImmutable::now(),
                lastError: $e,
            ));
        }
    }
}
