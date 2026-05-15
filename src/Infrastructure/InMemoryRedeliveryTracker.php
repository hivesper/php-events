<?php

namespace Vesper\Tool\Event\Infrastructure;

use Carbon\CarbonImmutable;
use Override;
use Throwable;
use Vesper\Tool\Event\DueRedelivery;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\RedeliveryTracker;

class InMemoryRedeliveryTracker implements RedeliveryTracker
{
    /** @var array<string, array{event: RawEvent, listener: string, status: RedeliveryStatus, attempt_number: int, next_retry_at: CarbonImmutable, last_error: ?string, created_at: CarbonImmutable, updated_at: CarbonImmutable}> */
    private array $rows = [];

    #[Override]
    public function schedule(
        RawEvent $event,
        string $listener,
        int $attemptNumber,
        CarbonImmutable $nextRetryAt,
        Throwable $lastError,
    ): void {
        $key = self::key($event->id, $listener);
        $now = CarbonImmutable::now();

        $this->rows[$key] = [
            'event' => $event,
            'listener' => $listener,
            'status' => RedeliveryStatus::PendingRetry,
            'attempt_number' => $attemptNumber,
            'next_retry_at' => $nextRetryAt,
            'last_error' => self::formatError($lastError),
            'created_at' => $this->rows[$key]['created_at'] ?? $now,
            'updated_at' => $now,
        ];
    }

    #[Override]
    public function nextDue(): ?DueRedelivery
    {
        $now = CarbonImmutable::now();
        $candidate = null;

        foreach ($this->rows as $row) {
            if ($row['status'] !== RedeliveryStatus::PendingRetry) {
                continue;
            }
            if ($row['next_retry_at']->greaterThan($now)) {
                continue;
            }
            if ($candidate === null || $row['next_retry_at']->lessThan($candidate['next_retry_at'])) {
                $candidate = $row;
            }
        }

        if ($candidate === null) {
            return null;
        }

        return new DueRedelivery(
            event: $candidate['event'],
            listener: $candidate['listener'],
            attemptNumber: $candidate['attempt_number'],
        );
    }

    #[Override]
    public function markFailedPermanently(string $eventId, string $listener, Throwable $lastError): void
    {
        $key = self::key($eventId, $listener);

        if (!isset($this->rows[$key])) {
            return;
        }

        $this->rows[$key]['status'] = RedeliveryStatus::Failed;
        $this->rows[$key]['last_error'] = self::formatError($lastError);
        $this->rows[$key]['updated_at'] = CarbonImmutable::now();
    }

    #[Override]
    public function markSucceeded(string $eventId, string $listener): void
    {
        $key = self::key($eventId, $listener);

        if (!isset($this->rows[$key])) {
            return;
        }

        $this->rows[$key]['status'] = RedeliveryStatus::Succeeded;
        $this->rows[$key]['updated_at'] = CarbonImmutable::now();
    }

    #[Override]
    public function retryNow(string $eventId, string $listener): void
    {
        $key = self::key($eventId, $listener);

        if (!isset($this->rows[$key])) {
            return;
        }

        $this->rows[$key]['status'] = RedeliveryStatus::PendingRetry;
        $this->rows[$key]['next_retry_at'] = CarbonImmutable::now();
        $this->rows[$key]['updated_at'] = CarbonImmutable::now();
    }

    private static function key(string $eventId, string $listener): string
    {
        return $eventId . '|' . $listener;
    }

    private static function formatError(Throwable $error): string
    {
        return $error::class . ': ' . $error->getMessage();
    }
}
