<?php

namespace Vesper\Tool\Event\Infrastructure\Redelivery;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Override;
use Vesper\Tool\Event\Redelivery\Redelivery;
use Vesper\Tool\Event\Redelivery\RedeliveryStore;

class InMemoryRedeliveryStore implements RedeliveryStore
{
    /** @var array<string, Redelivery> */
    private array $rows = [];

    #[Override]
    public function schedule(Redelivery $redelivery): void
    {
        $key = self::key($redelivery->eventId(), $redelivery->listener);

        if (isset($this->rows[$key])) {
            return;
        }

        $this->rows[$key] = $redelivery;
    }

    #[Override]
    public function next(): ?Redelivery
    {
        $now = CarbonImmutable::now();
        $candidate = null;
        $candidateKey = null;

        foreach ($this->rows as $key => $row) {
            if (!$row->isDue($now)) {
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

        $claimed = $candidate->claim();
        $this->rows[$candidateKey] = $claimed;

        return $claimed;
    }

    #[Override]
    public function update(Redelivery $redelivery): void
    {
        $key = self::key($redelivery->eventId(), $redelivery->listener);

        if (!isset($this->rows[$key]) || !$this->rows[$key]->isDispatching()) {
            return;
        }

        $this->rows[$key] = $redelivery;
    }

    #[Override]
    public function retryNow(string $eventId, string $listener): void
    {
        $key = self::key($eventId, $listener);

        if (!isset($this->rows[$key])) {
            return;
        }

        $this->rows[$key] = $this->rows[$key]->queueForImmediateRetry();
    }

    #[Override]
    public function recoverStuckRedeliveries(CarbonInterval $olderThan): int
    {
        $threshold = CarbonImmutable::now()->sub($olderThan);
        $recovered = 0;

        foreach ($this->rows as $key => $row) {
            if (!$row->isDispatching() || !$row->updatedAt->lessThan($threshold)) {
                continue;
            }

            $this->rows[$key] = $row->queueForImmediateRetry();
            $recovered++;
        }

        return $recovered;
    }

    private static function key(string $eventId, string $listener): string
    {
        return $eventId . '|' . $listener;
    }
}
