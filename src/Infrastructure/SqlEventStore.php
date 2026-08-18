<?php

namespace Vesper\Tool\Event\Infrastructure;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Closure;
use Override;
use PDO;
use Throwable;
use Vesper\Tool\Event\EventStore;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\RawEventStatus;

readonly class SqlEventStore implements EventStore
{
    /**
     * A closure is resolved on every call so add() can join whichever transaction the caller
     * has open. Handing over a fixed PDO writes the event on a second session, which commits
     * the event independently of the business change it describes.
     *
     * @param PDO|Closure(): PDO $connection
     * @param string|null $schema Qualifies the outbox tables when they live outside the
     *                            caller's default schema.
     */
    public function __construct(
        private PDO|Closure $connection,
        private ?string $schema = null,
    ) {}

    #[Override]
    public function add(RawEvent $event): void
    {
        $connection = $this->connection();

        $stmt = $connection->prepare(
            <<<SQL
                INSERT INTO {$this->table('event_outbox')} (id, name, status, payload, created_at, publish_at)
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

        $this->insertStatusAudit($connection, $event->id, $event->status->value);
    }

    #[Override]
    public function next(): ?RawEvent
    {
        $connection = $this->connection();

        $connection->beginTransaction();

        try {
            $row = $this->fetchNextPendingRow($connection);

            if ($row === null) {
                $connection->commit();
                return null;
            }

            /** @var array<string, mixed> $payload */
            $payload = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);

            $event = RawEvent::retrieve(
                id: $row['id'],
                name: $row['name'],
                status: RawEventStatus::pending,
                payload: $payload,
                createdAt: new CarbonImmutable($row['created_at']),
                publishAt: new CarbonImmutable($row['publish_at']),
            )->claim();

            $connection->prepare(
                "UPDATE {$this->table('event_outbox')} SET status = :status WHERE id = :id",
            )->execute(['status' => $event->status->value, 'id' => $event->id]);

            $this->insertStatusAudit($connection, $event->id, $event->status->value);

            $connection->commit();

            return $event;
        } catch (Throwable $e) {
            $this->rollBackIfActive($connection);

            throw $e;
        }
    }

    /** UPDATE is guarded by status='processing' so a row a sweep already reclaimed is not overwritten. */
    #[Override]
    public function markProcessed(RawEvent $event): void
    {
        $connection = $this->connection();

        $connection->beginTransaction();

        try {
            $connection->prepare(
                <<<SQL
                    UPDATE {$this->table('event_outbox')}
                        SET status = :status
                        WHERE id = :id AND status = 'processing'
                    SQL,
            )->execute(['status' => $event->status->value, 'id' => $event->id]);

            $this->insertStatusAudit($connection, $event->id, $event->status->value);

            $connection->commit();
        } catch (Throwable $e) {
            $this->rollBackIfActive($connection);

            throw $e;
        }
    }

    /**
     * Reset events wedged in `processing` back to `pending`. A row is "stuck" when its most
     * recent `processing` audit entry is older than $olderThan. Writes a `pending` audit row
     * tagged "Recovered from stuck processing state" so dashboards can tell organic vs. recovered
     * transitions apart. Returns the number of events recovered. Call from a separate scheduled
     * job; safe to run alongside the main worker.
     */
    #[Override]
    public function recoverStuckEvents(CarbonInterval $olderThan): int
    {
        $connection = $this->connection();

        $thresholdAt = CarbonImmutable::now()->sub($olderThan)->format('Y-m-d H:i:s.u');

        $stmt = $connection->prepare(
            <<<SQL
                    SELECT e.id FROM {$this->table('event_outbox')} e
                    WHERE e.status = 'processing'
                      AND NOT EXISTS (
                        SELECT 1 FROM {$this->table('event_outbox_status')} s
                        WHERE s.event_id = e.id
                          AND s.status = 'processing'
                          AND s.created_at >= :threshold
                      )
                SQL,
        );
        $stmt->execute(['threshold' => $thresholdAt]);

        /** @var list<string> $ids */
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($ids === []) {
            return 0;
        }

        $connection->beginTransaction();

        try {
            $updateStmt = $connection->prepare(
                "UPDATE {$this->table('event_outbox')} SET status = 'pending' WHERE id = :id AND status = 'processing'",
            );

            $recovered = 0;
            foreach ($ids as $id) {
                $updateStmt->execute(['id' => $id]);

                if ($updateStmt->rowCount() === 0) {
                    continue;
                }

                $this->insertStatusAudit($connection, $id, RawEventStatus::pending->value, 'Recovered from stuck processing state');
                $recovered++;
            }

            $connection->commit();

            return $recovered;
        } catch (Throwable $e) {
            $this->rollBackIfActive($connection);

            throw $e;
        }
    }

    private function connection(): PDO
    {
        return $this->connection instanceof Closure
            ? ($this->connection)()
            : $this->connection;
    }

    private function table(string $name): string
    {
        return $this->schema === null ? $name : "{$this->schema}.{$name}";
    }

    /**
     * @return array{id: string, name: string, status: string, payload: string, created_at: string, publish_at: string}|null
     */
    private function fetchNextPendingRow(PDO $connection): ?array
    {
        $lockClause = $this->lockingClause($connection);

        $stmt = $connection->prepare(
            <<<SQL
                    SELECT id, name, status, payload, created_at, publish_at
                        FROM {$this->table('event_outbox')} WHERE
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

    private function insertStatusAudit(PDO $connection, string $eventId, string $status, ?string $errorMessage = null): void
    {
        $stmt = $connection->prepare(
            <<<SQL
                INSERT INTO {$this->table('event_outbox_status')} (event_id, status, error_message, created_at)
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

    /** Guards against the implicit-commit case where a DDL statement closed the transaction before the failure. */
    private function rollBackIfActive(PDO $connection): void
    {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }

    private function lockingClause(PDO $connection): string
    {
        return match ($this->driverName($connection)) {
            'mysql' => 'FOR UPDATE SKIP LOCKED',
            default => '',
        };
    }

    private function driverName(PDO $connection): string
    {
        /** @var string */
        return $connection->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
