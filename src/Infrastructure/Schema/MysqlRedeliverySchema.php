<?php

namespace Vesper\Tool\Event\Infrastructure\Schema;

use PDO;
use PDOException;

class MysqlRedeliverySchema
{
    /**
     * Ensure the redelivery table and indexes exist.
     *
     * @throws PDOException
     */
    public static function create(PDO $connection): void
    {
        self::createIfNeeded(
            connection: $connection,
            table: 'event_outbox_redelivery',
            creationQuery: <<<SQL
                CREATE TABLE event_outbox_redelivery (
                    event_id        VARCHAR(36)  NOT NULL,
                    listener        VARCHAR(255) NOT NULL,
                    status          VARCHAR(32)  NOT NULL,
                    attempt_number  INT          NOT NULL,
                    next_retry_at   DATETIME(6)  NULL,
                    last_error      TEXT         NULL,
                    created_at      DATETIME(6)  NOT NULL,
                    updated_at      DATETIME(6)  NOT NULL,

                    PRIMARY KEY (event_id, listener),
                    INDEX idx_redelivery_due (status, next_retry_at),
                    CONSTRAINT fk_redelivery_event FOREIGN KEY (event_id) REFERENCES event_outbox(id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
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
