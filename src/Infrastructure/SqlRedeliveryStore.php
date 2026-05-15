<?php

namespace Vesper\Tool\Event\Infrastructure;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Override;
use PDO;
use RuntimeException;
use Throwable;
use Vesper\Tool\Event\DueRedelivery;
use Vesper\Tool\Event\Infrastructure\Schema\MysqlRedeliverySchema;
use Vesper\Tool\Event\Infrastructure\Schema\SqliteRedeliverySchema;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\RawEventStatus;
use Vesper\Tool\Event\RedeliveryRequest;
use Vesper\Tool\Event\RedeliveryStatus;
use Vesper\Tool\Event\RedeliveryStore;

readonly class SqlRedeliveryStore implements RedeliveryStore
{
    public function __construct(private PDO $connection)
    {
        $this->ensureRedeliverySchema();
    }

    #[Override]
    public function schedule(RedeliveryRequest $request): void
    {
        $now = CarbonImmutable::now()->format('Y-m-d H:i:s.u');
        $errorMessage = self::formatError($request->lastError);
        $nextRetryAtSql = $request->nextRetryAt->format('Y-m-d H:i:s.u');

        $sql = match ($this->driverName()) {
            'mysql' => <<<SQL
                INSERT INTO event_outbox_redelivery
                    (event_id, listener, status, attempt_number, next_retry_at, last_error, created_at, updated_at)
                    VALUES (:event_id, :listener, :status, :attempt_number, :next_retry_at, :last_error, :created_at, :updated_at)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    attempt_number = VALUES(attempt_number),
                    next_retry_at = VALUES(next_retry_at),
                    last_error = VALUES(last_error),
                    updated_at = VALUES(updated_at)
            SQL,
            'sqlite' => <<<SQL
                INSERT INTO event_outbox_redelivery
                    (event_id, listener, status, attempt_number, next_retry_at, last_error, created_at, updated_at)
                    VALUES (:event_id, :listener, :status, :attempt_number, :next_retry_at, :last_error, :created_at, :updated_at)
                ON CONFLICT (event_id, listener) DO UPDATE SET
                    status = excluded.status,
                    attempt_number = excluded.attempt_number,
                    next_retry_at = excluded.next_retry_at,
                    last_error = excluded.last_error,
                    updated_at = excluded.updated_at
            SQL,
            default => throw new RuntimeException('Unsupported database driver: ' . $this->driverName()),
        };

        $this->connection->prepare($sql)->execute([
            'event_id' => $request->event->id,
            'listener' => $request->listener,
            'status' => RedeliveryStatus::PendingRetry->value,
            'attempt_number' => $request->attemptNumber,
            'next_retry_at' => $nextRetryAtSql,
            'last_error' => $errorMessage,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    #[Override]
    public function next(): ?DueRedelivery
    {
        $startedTransaction = $this->beginTransactionIfNeeded();

        try {
            $row = $this->fetchNextDueRow();

            if ($row === null) {
                $this->commitIfStarted($startedTransaction);
                return null;
            }

            $this->connection->prepare(
                <<<SQL
                UPDATE event_outbox_redelivery
                    SET status = :status,
                        updated_at = :updated_at
                    WHERE event_id = :event_id AND listener = :listener
                SQL,
            )->execute([
                'status' => RedeliveryStatus::Dispatching->value,
                'updated_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
                'event_id' => $row['event_id'],
                'listener' => $row['listener'],
            ]);

            $this->commitIfStarted($startedTransaction);

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

            return new DueRedelivery(
                event: $event,
                listener: $row['listener'],
                attemptNumber: (int) $row['attempt_number'],
            );
        } catch (Throwable $e) {
            $this->rollBackIfStarted($startedTransaction);

            throw $e;
        }
    }

    /** UPDATE is guarded by status='dispatching' so a row a sweep already reclaimed is not overwritten. */
    #[Override]
    public function markFailedPermanently(string $eventId, string $listener, Throwable $lastError): void
    {
        $this->connection->prepare(
            <<<SQL
            UPDATE event_outbox_redelivery
                SET status = :new_status,
                    last_error = :last_error,
                    updated_at = :updated_at
                WHERE event_id = :event_id
                  AND listener = :listener
                  AND status = :current_status
            SQL,
        )->execute([
            'new_status' => RedeliveryStatus::Failed->value,
            'current_status' => RedeliveryStatus::Dispatching->value,
            'last_error' => self::formatError($lastError),
            'updated_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
            'event_id' => $eventId,
            'listener' => $listener,
        ]);
    }

    /** UPDATE is guarded by status='dispatching' so a row a sweep already reclaimed is not overwritten. */
    #[Override]
    public function markSucceeded(string $eventId, string $listener): void
    {
        $this->connection->prepare(
            <<<SQL
            UPDATE event_outbox_redelivery
                SET status = :new_status,
                    updated_at = :updated_at
                WHERE event_id = :event_id
                  AND listener = :listener
                  AND status = :current_status
            SQL,
        )->execute([
            'new_status' => RedeliveryStatus::Succeeded->value,
            'current_status' => RedeliveryStatus::Dispatching->value,
            'updated_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
            'event_id' => $eventId,
            'listener' => $listener,
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

    /**
     * @return array{event_id: string, listener: string, attempt_number: int, event_name: string, event_status: string, event_payload: string, event_created_at: string, event_publish_at: string}|null
     */
    private function fetchNextDueRow(): ?array
    {
        $lockClause = $this->lockingClause();

        $stmt = $this->connection->prepare(
            <<<SQL
                SELECT
                    r.event_id,
                    r.listener,
                    r.attempt_number,
                    e.name        AS event_name,
                    e.status      AS event_status,
                    e.payload     AS event_payload,
                    e.created_at  AS event_created_at,
                    e.publish_at  AS event_publish_at
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

        /** @var array{event_id: string, listener: string, attempt_number: int, event_name: string, event_status: string, event_payload: string, event_created_at: string, event_publish_at: string}|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private static function formatError(Throwable $error): string
    {
        return $error::class . ': ' . $error->getMessage();
    }

    private function beginTransactionIfNeeded(): bool
    {
        if ($this->connection->inTransaction()) {
            return false;
        }

        $this->connection->beginTransaction();

        return true;
    }

    private function commitIfStarted(bool $started): void
    {
        if ($started) {
            $this->connection->commit();
        }
    }

    private function rollBackIfStarted(bool $started): void
    {
        if ($started && $this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }

    private function ensureRedeliverySchema(): void
    {
        $driver = $this->driverName();

        match ($driver) {
            'mysql' => MysqlRedeliverySchema::create($this->connection),
            'sqlite' => SqliteRedeliverySchema::create($this->connection),
            default => throw new RuntimeException('Unsupported database driver: ' . $driver),
        };
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
        $driver = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        assert(is_string($driver));

        return $driver;
    }
}
