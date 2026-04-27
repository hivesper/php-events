<?php

namespace Vesper\Tool\Event\Infrastructure;

use Carbon\CarbonImmutable;
use JsonException;
use Override;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use Vesper\Tool\Event\DueRedelivery;
use Vesper\Tool\Event\Infrastructure\Schema\MysqlRedeliverySchema;
use Vesper\Tool\Event\Infrastructure\Schema\SqliteRedeliverySchema;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\RawEventStatus;
use Vesper\Tool\Event\RedeliveryTracker;

readonly class SqlRedeliveryTracker implements RedeliveryTracker
{
    public const string STATUS_PENDING = 'pending_retry';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_SUCCEEDED = 'succeeded';

    public function __construct(private PDO $connection)
    {
        $this->ensureRedeliverySchema();
    }

    /**
     * @throws PDOException
     */
    #[Override]
    public function schedule(
        RawEvent $event,
        string $listener,
        int $attemptNumber,
        CarbonImmutable $nextRetryAt,
        Throwable $lastError,
    ): void {
        $now = CarbonImmutable::now()->format('Y-m-d H:i:s.u');
        $errorMessage = self::formatError($lastError);
        $nextRetryAtSql = $nextRetryAt->format('Y-m-d H:i:s.u');

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
            'event_id' => $event->id,
            'listener' => $listener,
            'status' => self::STATUS_PENDING,
            'attempt_number' => $attemptNumber,
            'next_retry_at' => $nextRetryAtSql,
            'last_error' => $errorMessage,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @throws JsonException
     * @throws PDOException
     */
    #[Override]
    public function nextDue(): ?DueRedelivery
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
                  AND r.next_retry_at IS NOT NULL
                  AND r.next_retry_at <= :now
                ORDER BY r.next_retry_at
                LIMIT 1 {$lockClause}
            SQL,
        );

        $stmt->execute([
            'status' => self::STATUS_PENDING,
            'now' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
        ]);

        /** @var array{event_id: string, listener: string, attempt_number: int, event_name: string, event_status: string, event_payload: string, event_created_at: string, event_publish_at: string}|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
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

        return new DueRedelivery(
            event: $event,
            listener: $row['listener'],
            attemptNumber: (int) $row['attempt_number'],
        );
    }

    /**
     * @throws PDOException
     */
    #[Override]
    public function markFailedPermanently(string $eventId, string $listener, Throwable $lastError): void
    {
        $this->connection->prepare(
            <<<SQL
            UPDATE event_outbox_redelivery
                SET status = :status,
                    next_retry_at = NULL,
                    last_error = :last_error,
                    updated_at = :updated_at
                WHERE event_id = :event_id AND listener = :listener
            SQL,
        )->execute([
            'status' => self::STATUS_FAILED,
            'last_error' => self::formatError($lastError),
            'updated_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
            'event_id' => $eventId,
            'listener' => $listener,
        ]);
    }

    /**
     * @throws PDOException
     */
    #[Override]
    public function markSucceeded(string $eventId, string $listener): void
    {
        $this->connection->prepare(
            <<<SQL
            UPDATE event_outbox_redelivery
                SET status = :status,
                    next_retry_at = NULL,
                    updated_at = :updated_at
                WHERE event_id = :event_id AND listener = :listener
            SQL,
        )->execute([
            'status' => self::STATUS_SUCCEEDED,
            'updated_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
            'event_id' => $eventId,
            'listener' => $listener,
        ]);
    }

    /**
     * @throws PDOException
     */
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
            'status' => self::STATUS_PENDING,
            'next_retry_at' => $now,
            'updated_at' => $now,
            'event_id' => $eventId,
            'listener' => $listener,
        ]);
    }

    private static function formatError(Throwable $error): string
    {
        return $error::class . ': ' . $error->getMessage();
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
