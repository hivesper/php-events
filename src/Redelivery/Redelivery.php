<?php

namespace Vesper\Tool\Event\Redelivery;

use Carbon\CarbonImmutable;
use DomainException;
use InvalidArgumentException;
use Throwable;
use Vesper\Tool\Event\RawEvent;

final class Redelivery
{
    private function __construct(
        public readonly RawEvent $event,
        public readonly string $listener,
        public private(set) RedeliveryStatus $status,
        public private(set) int $attemptNumber,
        public private(set) CarbonImmutable $nextRetryAt,
        public private(set) string $lastError,
        public readonly CarbonImmutable $createdAt,
        public private(set) CarbonImmutable $updatedAt,
    ) {
        if ($attemptNumber < 1) {
            throw new InvalidArgumentException(
                "attemptNumber must be at least 1 (the attempt that just failed), got $attemptNumber",
            );
        }

        if (trim($listener) === '') {
            throw new InvalidArgumentException('listener must not be empty');
        }
    }

    public static function schedule(
        RawEvent $event,
        string $listener,
        int $attemptNumber,
        CarbonImmutable $nextRetryAt,
        Throwable $lastError,
    ): self {
        $now = CarbonImmutable::now();

        return new self(
            event: $event,
            listener: $listener,
            status: RedeliveryStatus::PendingRetry,
            attemptNumber: $attemptNumber,
            nextRetryAt: $nextRetryAt,
            lastError: self::formatError($lastError),
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public static function retrieve(
        RawEvent $event,
        string $listener,
        RedeliveryStatus $status,
        int $attemptNumber,
        CarbonImmutable $nextRetryAt,
        string $lastError,
        CarbonImmutable $createdAt,
        CarbonImmutable $updatedAt,
    ): self {
        return new self(
            event: $event,
            listener: $listener,
            status: $status,
            attemptNumber: $attemptNumber,
            nextRetryAt: $nextRetryAt,
            lastError: $lastError,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function eventId(): string
    {
        return $this->event->id;
    }

    public function eventName(): string
    {
        return $this->event->name;
    }

    public function isFor(string $listenerKey): bool
    {
        return $this->listener === $listenerKey;
    }

    public function isDue(CarbonImmutable $now): bool
    {
        return $this->status === RedeliveryStatus::PendingRetry
            && $this->nextRetryAt->lessThanOrEqualTo($now);
    }

    public function isPending(): bool
    {
        return $this->status === RedeliveryStatus::PendingRetry;
    }

    public function isDispatching(): bool
    {
        return $this->status === RedeliveryStatus::Dispatching;
    }

    public function isFailed(): bool
    {
        return $this->status === RedeliveryStatus::Failed;
    }

    public function isSucceeded(): bool
    {
        return $this->status === RedeliveryStatus::Succeeded;
    }

    public function claim(): self
    {
        $this->requireStatus(RedeliveryStatus::PendingRetry, 'claim');

        $this->status = RedeliveryStatus::Dispatching;
        $this->updatedAt = CarbonImmutable::now();

        return $this;
    }

    public function rescheduleTo(int $attemptNumber, CarbonImmutable $nextRetryAt, Throwable $lastError): self
    {
        $this->requireStatus(RedeliveryStatus::Dispatching, 'rescheduleTo');

        $this->status = RedeliveryStatus::PendingRetry;
        $this->attemptNumber = $attemptNumber;
        $this->nextRetryAt = $nextRetryAt;
        $this->lastError = self::formatError($lastError);
        $this->updatedAt = CarbonImmutable::now();

        return $this;
    }

    public function markSucceeded(): self
    {
        $this->requireStatus(RedeliveryStatus::Dispatching, 'markSucceeded');

        $this->status = RedeliveryStatus::Succeeded;
        $this->updatedAt = CarbonImmutable::now();

        return $this;
    }

    public function markFailedPermanently(Throwable $lastError): self
    {
        $this->requireStatus(RedeliveryStatus::Dispatching, 'markFailedPermanently');

        $this->status = RedeliveryStatus::Failed;
        $this->lastError = self::formatError($lastError);
        $this->updatedAt = CarbonImmutable::now();

        return $this;
    }

    /** Admin override: any status → PendingRetry, retry now. Preserves attemptNumber so the retry-policy ceiling still applies. */
    public function queueForImmediateRetry(): self
    {
        $now = CarbonImmutable::now();

        $this->status = RedeliveryStatus::PendingRetry;
        $this->nextRetryAt = $now;
        $this->updatedAt = $now;

        return $this;
    }

    private function requireStatus(RedeliveryStatus $expected, string $operation): void
    {
        if ($this->status !== $expected) {
            throw new DomainException(
                "Cannot {$operation} a redelivery in status {$this->status->value} (expected {$expected->value}).",
            );
        }
    }

    private static function formatError(Throwable $error): string
    {
        return $error::class . ': ' . $error->getMessage();
    }
}
