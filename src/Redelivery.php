<?php

namespace Vesper\Tool\Event;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The canonical domain entity for a per-(event, listener) redelivery row —
 * the in-memory analogue of an event_outbox_redelivery SQL row.
 *
 * Fully immutable: lifecycle transitions return a new instance with the
 * relevant fields updated, mirroring the RawEvent / RawEventStatus pattern.
 */
readonly class Redelivery
{
    private function __construct(
        public RawEvent $event,
        public string $listener,
        public RedeliveryStatus $status,
        public int $attemptNumber,
        public CarbonImmutable $nextRetryAt,
        public string $lastError,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
    ) {
    }

    /** Build a fresh pending_retry row from a schedule request. */
    public static function fromRequest(RedeliveryRequest $request): self
    {
        $now = CarbonImmutable::now();

        return new self(
            event: $request->event,
            listener: $request->listener,
            status: RedeliveryStatus::PendingRetry,
            attemptNumber: $request->attemptNumber,
            nextRetryAt: $request->nextRetryAt,
            lastError: self::formatError($request->lastError),
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /** Reconstruct from persisted storage. */
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

    /** Re-schedule an existing row: bumps attempt count, resets status to pending_retry, preserves createdAt. */
    public function rescheduled(RedeliveryRequest $request): self
    {
        return new self(
            event: $this->event,
            listener: $this->listener,
            status: RedeliveryStatus::PendingRetry,
            attemptNumber: $request->attemptNumber,
            nextRetryAt: $request->nextRetryAt,
            lastError: self::formatError($request->lastError),
            createdAt: $this->createdAt,
            updatedAt: CarbonImmutable::now(),
        );
    }

    public function markedFailedPermanently(Throwable $lastError): self
    {
        return new self(
            event: $this->event,
            listener: $this->listener,
            status: RedeliveryStatus::Failed,
            attemptNumber: $this->attemptNumber,
            nextRetryAt: $this->nextRetryAt,
            lastError: self::formatError($lastError),
            createdAt: $this->createdAt,
            updatedAt: CarbonImmutable::now(),
        );
    }

    public function markedSucceeded(): self
    {
        return new self(
            event: $this->event,
            listener: $this->listener,
            status: RedeliveryStatus::Succeeded,
            attemptNumber: $this->attemptNumber,
            nextRetryAt: $this->nextRetryAt,
            lastError: $this->lastError,
            createdAt: $this->createdAt,
            updatedAt: CarbonImmutable::now(),
        );
    }

    /** Reset to pending_retry with next_retry_at = now(), preserving attempt count. */
    public function queuedForImmediateRetry(): self
    {
        $now = CarbonImmutable::now();

        return new self(
            event: $this->event,
            listener: $this->listener,
            status: RedeliveryStatus::PendingRetry,
            attemptNumber: $this->attemptNumber,
            nextRetryAt: $now,
            lastError: $this->lastError,
            createdAt: $this->createdAt,
            updatedAt: $now,
        );
    }

    private static function formatError(Throwable $error): string
    {
        return $error::class . ': ' . $error->getMessage();
    }
}
