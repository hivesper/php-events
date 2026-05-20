<?php

namespace Vesper\Tool\Event\Infrastructure\Schema;

use PDO;

class MysqlRedeliverySchema
{
    public static function create(PDO $connection): void
    {
        self::createIfNeeded(
            connection: $connection,
            table: 'event_outbox_redelivery',
            creationQuery: <<<SQL
                    CREATE TABLE event_outbox_redelivery (
                        event_id        VARCHAR(36)  NOT NULL,
                        listener        VARCHAR(500) NOT NULL,
                        status          VARCHAR(32)  NOT NULL,
                        attempt_number  INT          NOT NULL,
                        next_retry_at   DATETIME(6)  NOT NULL,
                        last_error      TEXT         NULL,
                        created_at      DATETIME(6)  NOT NULL,
                        updated_at      DATETIME(6)  NOT NULL,

                        PRIMARY KEY (event_id, listener),
                        INDEX idx_redelivery_due (status, next_retry_at),
                        INDEX idx_redelivery_event_id (event_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                SQL,
        );
    }

    private static function createIfNeeded(PDO $connection, string $table, string $creationQuery): void
    {
        $stmt = $connection->prepare(
            <<<MYSQL
                    SELECT COUNT(*) FROM information_schema.tables
                        WHERE table_schema = DATABASE()
                          AND table_name = :table
                MYSQL,
        );
        $stmt->execute(['table' => $table]);

        if ($stmt->fetchColumn()) {
            return;
        }

        $connection->exec($creationQuery);
    }
}
