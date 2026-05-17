<?php

namespace Vesper\Tool\Event\Infrastructure;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Override;
use PDO;
use Throwable;
use Vesper\Tool\Event\EventStore;
use Vesper\Tool\Event\RawEvent;
use Vesper\Tool\Event\RawEventStatus;

readonly class SqlEventStore implements EventStore
{
    public function __construct(private PDO $connection) {}

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

    #[Override]
    public function next(): ?RawEvent
    {
        $this->connection->beginTransaction();

        try {
            $row = $this->fetchNextPendingRow();

            if ($row === null) {
                $this->connection->commit();
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

            $this->connection->prepare(
                "UPDATE event_outbox SET status = :status WHERE id = :id",
            )->execute(['status' => $event->status->value, 'id' => $event->id]);

            $this->insertStatusAudit($event->id, $event->status->value);

            $this->connection->commit();

            return $event;
        } catch (Throwable $e) {
            $this->rollBackIfActive();

            throw $e;
        }
    }

    /** UPDATE is guarded by status='processing' so a row a sweep already reclaimed is not overwritten. */
    #[Override]
    public function markProcessed(RawEvent $event): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->prepare(
                <<<SQL
                    UPDATE event_outbox
                        SET status = :status
                        WHERE id = :id AND status = 'processing'
                    SQL,
            )->execute(['status' => $event->status->value, 'id' => $event->id]);

            $this->insertStatusAudit($event->id, $event->status->value);

            $this->connection->commit();
        } catch (Throwable $e) {
            $this->rollBackIfActive();

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
        $thresholdAt = CarbonImmutable::now()->sub($olderThan)->format('Y-m-d H:i:s.u');

        $stmt = $this->connection->prepare(
            <<<SQL
                    SELECT e.id FROM event_outbox e
                    WHERE e.status = 'processing'
                      AND NOT EXISTS (
                        SELECT 1 FROM event_outbox_status s
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

        $this->connection->beginTransaction();

        try {
            $updateStmt = $this->connection->prepare(
                "UPDATE event_outbox SET status = 'pending' WHERE id = :id AND status = 'processing'",
            );

            $recovered = 0;
            foreach ($ids as $id) {
                $updateStmt->execute(['id' => $id]);

                if ($updateStmt->rowCount() === 0) {
                    continue;
                }

                $this->insertStatusAudit($id, RawEventStatus::pending->value, 'Recovered from stuck processing state');
                $recovered++;
            }

            $this->connection->commit();

            return $recovered;
        } catch (Throwable $e) {
            $this->rollBackIfActive();

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
