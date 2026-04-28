<?php

namespace Vesper\Tool\Event\Infrastructure;

use Carbon\CarbonImmutable;
use JsonException;
use Override;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use Vesper\Tool\Event\EventStore;
use Vesper\Tool\Event\Infrastructure\Schema\MysqlEventStoreSchema;
use Vesper\Tool\Event\Infrastructure\Schema\SqliteEventStoreSchema;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\RawEventStatus;

readonly class SqlEventStore implements EventStore
{
    public function __construct(private PDO $connection)
    {
        $this->ensureOutboxSchema();
    }

    /**
     * Add a new event to the outbox. Inserts both the event row and a
     * 'pending' audit row in the caller's transaction.
     *
     * @throws JsonException
     * @throws PDOException
     */
    #[Override]
    public function add(RawEvent $event): void
    {
        $stmt = $this->connection->prepare(
            <<<SQL
            INSERT INTO event_outbox (id, name, status, payload, created_at, publish_at)
                VALUES (:id, :name, :status, :payload, :created_at, :publish_at)
            SQL,
        );

        $stmt->execute([
            'id' => $event->id,
            'name' => $event->name,
            'status' => $event->status->value,
            'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
            'created_at' => $event->createdAt->format('Y-m-d H:i:s.u'),
            'publish_at' => $event->publishAt->format('Y-m-d H:i:s.u'),
        ]);

        $this->insertStatusAudit($event->id, $event->status->value);
    }

    /**
     * Claim the next pending event for this worker (worker-safe via FOR UPDATE
     * SKIP LOCKED on MySQL). Transitions the row from `pending` to `processing`
     * inside a single transaction together with the audit row insert.
     *
     * @throws JsonException
     * @throws PDOException
     */
    #[Override]
    public function next(): ?RawEvent
    {
        $startedTransaction = $this->beginTransactionIfNeeded();

        try {
            $row = $this->fetchNextPendingRow();

            if ($row === null) {
                $this->commitIfStarted($startedTransaction);
                return null;
            }

            $this->connection->prepare(
                "UPDATE event_outbox SET status = 'processing' WHERE id = :id",
            )->execute(['id' => $row['id']]);

            $this->insertStatusAudit($row['id'], RawEventStatus::processing->value);

            $this->commitIfStarted($startedTransaction);

            /** @var array<string, mixed> $payload */
            $payload = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);

            return RawEvent::retrieve(
                id: $row['id'],
                name: $row['name'],
                status: RawEventStatus::processing,
                payload: $payload,
                createdAt: new CarbonImmutable($row['created_at']),
                publishAt: new CarbonImmutable($row['publish_at']),
            );
        } catch (Throwable $e) {
            $this->rollBackIfStarted($startedTransaction);
            throw $e;
        }
    }

    /**
     * Advance an event from `processing` to `processed`. Inserts the matching
     * audit row in the same transaction. The UPDATE is guarded with
     * `status = 'processing'` so a stuck row that's already been recovered by
     * a sweep won't be silently overwritten.
     *
     * @throws PDOException
     */
    #[Override]
    public function markProcessed(string $eventId): void
    {
        $startedTransaction = $this->beginTransactionIfNeeded();

        try {
            $this->connection->prepare(
                <<<SQL
                UPDATE event_outbox
                    SET status = 'processed'
                    WHERE id = :id AND status = 'processing'
                SQL,
            )->execute(['id' => $eventId]);

            $this->insertStatusAudit($eventId, RawEventStatus::processed->value);

            $this->commitIfStarted($startedTransaction);
        } catch (Throwable $e) {
            $this->rollBackIfStarted($startedTransaction);
            throw $e;
        }
    }

    /**
     * @return array{id: string, name: string, status: string, payload: string, created_at: string, publish_at: string}|null
     */
    private function fetchNextPendingRow(): ?array
    {
        $lockClause = $this->lockingClause();

        $stmt = $this->connection->prepare(
            <<<SQL
                SELECT id, name, status, payload, created_at, publish_at
                    FROM event_outbox WHERE
                        status = 'pending' AND
                        publish_at <= :now
                    ORDER BY publish_at
                    LIMIT 1 {$lockClause}
            SQL,
        );

        $stmt->execute([
            'now' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
        ]);

        /** @var array{id: string, name: string, status: string, payload: string, created_at: string, publish_at: string}|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function insertStatusAudit(string $eventId, string $status, ?string $errorMessage = null): void
    {
        $stmt = $this->connection->prepare(
            <<<SQL
            INSERT INTO event_outbox_status (event_id, status, error_message, created_at)
                VALUES (:event_id, :status, :error_message, :created_at)
            SQL,
        );

        $stmt->execute([
            'event_id' => $eventId,
            'status' => $status,
            'error_message' => $errorMessage,
            'created_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
        ]);
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

    private function ensureOutboxSchema(): void
    {
        $driver = $this->driverName();

        match ($driver) {
            'mysql' => MysqlEventStoreSchema::create($this->connection),
            'sqlite' => SqliteEventStoreSchema::create($this->connection),
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
