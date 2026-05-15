<?php

namespace Vesper\Tool\Event\Infrastructure;

use Carbon\CarbonImmutable;
use Override;
use Throwable;
use Vesper\Tool\Event\DueRedelivery;
use Vesper\Tool\Event\Redelivery;
use Vesper\Tool\Event\RedeliveryRequest;
use Vesper\Tool\Event\RedeliveryStatus;
use Vesper\Tool\Event\RedeliveryStore;

class InMemoryRedeliveryStore implements RedeliveryStore
{
    /** @var array<string, Redelivery> */
    private array $rows = [];

    #[Override]
    public function schedule(RedeliveryRequest $request): void
    {
        $key = self::key($request->event->id, $request->listener);

        $this->rows[$key] = isset($this->rows[$key])
            ? $this->rows[$key]->rescheduled($request)
            : Redelivery::fromRequest($request);
    }

    #[Override]
    public function next(): ?DueRedelivery
    {
        $now = CarbonImmutable::now();
        $candidate = null;
        $candidateKey = null;

        foreach ($this->rows as $key => $row) {
            if ($row->status !== RedeliveryStatus::PendingRetry) {
                continue;
            }
            if ($row->nextRetryAt->greaterThan($now)) {
                continue;
            }
            if ($candidate === null || $row->nextRetryAt->lessThan($candidate->nextRetryAt)) {
                $candidate = $row;
                $candidateKey = $key;
            }
        }

        if ($candidate === null || $candidateKey === null) {
            return null;
        }

        $this->rows[$candidateKey] = $candidate->claimed();

        return new DueRedelivery(
            event: $candidate->event,
            listener: $candidate->listener,
            attemptNumber: $candidate->attemptNumber,
        );
    }

    #[Override]
    public function markFailedPermanently(string $eventId, string $listener, Throwable $lastError): void
    {
        $key = self::key($eventId, $listener);

        if (!isset($this->rows[$key]) || $this->rows[$key]->status !== RedeliveryStatus::Dispatching) {
            return;
        }

        $this->rows[$key] = $this->rows[$key]->markedFailedPermanently($lastError);
    }

    #[Override]
    public function markSucceeded(string $eventId, string $listener): void
    {
        $key = self::key($eventId, $listener);

        if (!isset($this->rows[$key]) || $this->rows[$key]->status !== RedeliveryStatus::Dispatching) {
            return;
        }

        $this->rows[$key] = $this->rows[$key]->markedSucceeded();
    }

    #[Override]
    public function retryNow(string $eventId, string $listener): void
    {
        $key = self::key($eventId, $listener);

        if (!isset($this->rows[$key])) {
            return;
        }

        $this->rows[$key] = $this->rows[$key]->queuedForImmediateRetry();
    }

    private static function key(string $eventId, string $listener): string
    {
        return $eventId . '|' . $listener;
    }
}
