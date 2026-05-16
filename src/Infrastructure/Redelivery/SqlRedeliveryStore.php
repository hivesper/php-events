<?php

namespace Vesper\Tool\Event\Infrastructure\Redelivery;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Override;
use PDO;
use RuntimeException;
use Throwable;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\RawEventStatus;
use Vesper\Tool\Event\Redelivery\Redelivery;
use Vesper\Tool\Event\Redelivery\RedeliveryStatus;
use Vesper\Tool\Event\Redelivery\RedeliveryStore;

readonly class SqlRedeliveryStore implements RedeliveryStore
{
    public function __construct(private PDO $connection) {}

    #[Override]
    public function schedule(Redelivery $redelivery): void
    {
        $sql = match ($this->driverName()) {
            'mysql' => <<<SQL
                    INSERT IGNORE INTO event_outbox_redelivery
                        (event_id, listener, status, attempt_number, next_retry_at, last_error, created_at, updated_at)
                        VALUES (:event_id, :listener, :status, :attempt_number, :next_retry_at, :last_error, :created_at, :updated_at)
                SQL,
            'sqlite' => <<<SQL
                    INSERT INTO event_outbox_redelivery
                        (event_id, listener, status, attempt_number, next_retry_at, last_error, created_at, updated_at)
                        VALUES (:event_id, :listener, :status, :attempt_number, :next_retry_at, :last_error, :created_at, :updated_at)
                    ON CONFLICT (event_id, listener) DO NOTHING
                SQL,
            default => throw new RuntimeException('Unsupported database driver: ' . $this->driverName()),
        };

        $this->connection->prepare($sql)->execute([
            'event_id' => $redelivery->eventId(),
            'listener' => $redelivery->listener,
            'status' => $redelivery->status->value,
            'attempt_number' => $redelivery->attemptNumber,
            'next_retry_at' => $redelivery->nextRetryAt->format('Y-m-d H:i:s.u'),
            'last_error' => $redelivery->lastError,
            'created_at' => $redelivery->createdAt->format('Y-m-d H:i:s.u'),
            'updated_at' => $redelivery->updatedAt->format('Y-m-d H:i:s.u'),
        ]);
    }

    #[Override]
    public function next(): ?Redelivery
    {
        $this->connection->beginTransaction();

        try {
            $row = $this->fetchNextDueRow();

            if ($row === null) {
                $this->connection->commit();

                return null;
            }

            $claimed = $row->claim();
            $this->writeClaim($claimed);
            $this->connection->commit();

            return $claimed;
        } catch (Throwable $e) {
            $this->rollBackIfActive();

            throw $e;
        }
    }

    /** UPDATE is guarded by status='dispatching' so a sweeper that has already reset the row is not overwritten. */
    #[Override]
    public function update(Redelivery $redelivery): void
    {
        $this->connection->prepare(
            <<<SQL
                UPDATE event_outbox_redelivery
                    SET status = :new_status,
                        attempt_number = :attempt_number,
                        next_retry_at = :next_retry_at,
                        last_error = :last_error,
                        updated_at = :updated_at
                    WHERE event_id = :event_id
                      AND listener = :listener
                      AND status = :current_status
                SQL,
        )->execute([
            'new_status' => $redelivery->status->value,
            'attempt_number' => $redelivery->attemptNumber,
            'next_retry_at' => $redelivery->nextRetryAt->format('Y-m-d H:i:s.u'),
            'last_error' => $redelivery->lastError,
            'updated_at' => $redelivery->updatedAt->format('Y-m-d H:i:s.u'),
            'event_id' => $redelivery->eventId(),
            'listener' => $redelivery->listener,
            'current_status' => RedeliveryStatus::Dispatching->value,
        ]);
    }

    #[Override]
    public function retryNow(string $eventId, string $listener): void
    {
        $now = CarbonImmutable::now()->format('Y-m-d H:i:s.u');

        $this->connection->prepare(
            <<<SQL
                UPDATE event_outbox_redelivery
                    SET status = :status,
                        next_retry_at = :next_retry_at,
                        updated_at = :updated_at
                    WHERE event_id = :event_id AND listener = :listener
                SQL,
        )->execute([
            'status' => RedeliveryStatus::PendingRetry->value,
            'next_retry_at' => $now,
            'updated_at' => $now,
            'event_id' => $eventId,
            'listener' => $listener,
        ]);
    }

    /**
     * Reset redelivery rows wedged in `dispatching` back to `pending_retry`. A row is "stuck"
     * when its updated_at is older than $olderThan. Returns the number of rows recovered.
     * Call from a separate scheduled job; safe to run alongside the redelivery worker.
     */
    public function recoverStuckRedeliveries(CarbonInterval $olderThan): int
    {
        $now = CarbonImmutable::now()->format('Y-m-d H:i:s.u');
        $thresholdAt = CarbonImmutable::now()->sub($olderThan)->format('Y-m-d H:i:s.u');

        $stmt = $this->connection->prepare(
            <<<SQL
                UPDATE event_outbox_redelivery
                    SET status = :pending,
                        updated_at = :now
                    WHERE status = :dispatching
                      AND updated_at < :threshold
                SQL,
        );
        $stmt->execute([
            'pending' => RedeliveryStatus::PendingRetry->value,
            'dispatching' => RedeliveryStatus::Dispatching->value,
            'now' => $now,
            'threshold' => $thresholdAt,
        ]);

        return $stmt->rowCount();
    }

    private function writeClaim(Redelivery $claimed): void
    {
        $this->connection->prepare(
            <<<SQL
                UPDATE event_outbox_redelivery
                    SET status = :status,
                        updated_at = :updated_at
                    WHERE event_id = :event_id AND listener = :listener
                SQL,
        )->execute([
            'status' => $claimed->status->value,
            'updated_at' => $claimed->updatedAt->format('Y-m-d H:i:s.u'),
            'event_id' => $claimed->eventId(),
            'listener' => $claimed->listener,
        ]);
    }

    private function fetchNextDueRow(): ?Redelivery
    {
        $lockClause = $this->lockingClause();

        $stmt = $this->connection->prepare(
            <<<SQL
                    SELECT
                        r.event_id,
                        r.listener,
                        r.status         AS r_status,
                        r.attempt_number,
                        r.next_retry_at,
                        r.last_error,
                        r.created_at     AS r_created_at,
                        r.updated_at     AS r_updated_at,
                        e.name           AS event_name,
                        e.status         AS event_status,
                        e.payload        AS event_payload,
                        e.created_at     AS event_created_at,
                        e.publish_at     AS event_publish_at
                    FROM event_outbox_redelivery r
                    INNER JOIN event_outbox e ON e.id = r.event_id
                    WHERE r.status = :status
                      AND r.next_retry_at <= :now
                    ORDER BY r.next_retry_at
                    LIMIT 1 {$lockClause}
                SQL,
        );

        $stmt->execute([
            'status' => RedeliveryStatus::PendingRetry->value,
            'now' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
        ]);

        /** @var array{event_id: string, listener: string, r_status: string, attempt_number: int, next_retry_at: string, last_error: ?string, r_created_at: string, r_updated_at: string, event_name: string, event_status: string, event_payload: string, event_created_at: string, event_publish_at: string}|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (false === $row) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($row['event_payload'], true, flags: JSON_THROW_ON_ERROR);

        $event = RawEvent::retrieve(
            id: $row['event_id'],
            name: $row['event_name'],
            status: RawEventStatus::from($row['event_status']),
            payload: $payload,
            createdAt: new CarbonImmutable($row['event_created_at']),
            publishAt: new CarbonImmutable($row['event_publish_at']),
        );

        return Redelivery::retrieve(
            event: $event,
            listener: $row['listener'],
            status: RedeliveryStatus::from($row['r_status']),
            attemptNumber: (int) $row['attempt_number'],
            nextRetryAt: new CarbonImmutable($row['next_retry_at']),
            lastError: $row['last_error'] ?? '',
            createdAt: new CarbonImmutable($row['r_created_at']),
            updatedAt: new CarbonImmutable($row['r_updated_at']),
        );
    }

    /** Guards against the implicit-commit case where a DDL statement closed the transaction before the failure. */
    private function rollBackIfActive(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }

    private function lockingClause(): string
    {
        return match ($this->driverName()) {
            'mysql' => 'FOR UPDATE SKIP LOCKED',
            default => '',
        };
    }

    private function driverName(): string
    {
        /** @var string */
        return $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
