<?php

namespace Tcds\Io\Ray\Infrastructure;

use Carbon\CarbonImmutable;
use JsonException;
use Override;
use PDO;
use PDOException;
use RuntimeException;
use Tcds\Io\Ray\EventStore;
use Tcds\Io\Ray\Infrastructure\Schema\MysqlEventStoreSchema;
use Tcds\Io\Ray\Infrastructure\Schema\SqliteEventStoreSchema;
use Tcds\Io\Ray\RayEvent;
use Tcds\Io\Ray\RayEventStatus;

readonly class SqlEventStore implements EventStore
{
    public function __construct(private PDO $connection)
    {
        $this->ensureOutboxSchema();
    }

    /**
     * Add a new event to the outbox
     *
     * @throws JsonException
     * @throws PDOException
     */
    #[Override]
    public function add(RayEvent $event): void
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
    }

    /**
     * Retrieve the next pending event (worker-safe)
     *
     * @throws JsonException
     * @throws PDOException
     */
    #[Override]
    public function next(): ?RayEvent
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
            'now' => CarbonImmutable::now()->format('Y-m-d H:i:s'),
        ]);

        /** @var array{id: string, name: string, status: string, payload: string, created_at: string, publish_at: string}|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $this->connection->prepare(
            "UPDATE event_outbox SET status = 'processed' WHERE id = :id",
        )->execute(['id' => $row['id']]);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);

        return RayEvent::retrieve(
            id: $row['id'],
            name: $row['name'],
            status: RayEventStatus::from($row['status']),
            payload: $payload,
            createdAt: new CarbonImmutable($row['created_at']),
            publishAt: new CarbonImmutable($row['publish_at']),
        );
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
