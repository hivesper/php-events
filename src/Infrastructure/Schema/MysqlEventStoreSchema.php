<?php

namespace Tcds\Io\Ray\Infrastructure\Schema;

use PDO;
use PDOException;

class MysqlEventStoreSchema
{
    /**
     * Ensure the outbox tables and indexes exist
     *
     * @throws PDOException
     */
    public static function create(PDO $connection): void
    {
        self::createIfNeeded(
            connection: $connection,
            table: 'event_outbox',
            creationQuery: <<<SQL
                CREATE TABLE event_outbox (
                    id           VARCHAR(32)  NOT NULL PRIMARY KEY,
                    name         VARCHAR(255) NOT NULL,
                    status       VARCHAR(255) NOT NULL,
                    payload      JSON         NOT NULL,
                    created_at   DATETIME     NOT NULL,
                    publish_at   DATETIME     NOT NULL,

                    INDEX idx_event_outbox_status_publish (status, publish_at),
                    INDEX idx_event_outbox_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            SQL,
        );

        self::createIfNeeded(
            connection: $connection,
            table: 'event_outbox_status',
            creationQuery: <<<SQL
                CREATE TABLE event_outbox_status (
                    event_id      VARCHAR(32) NOT NULL,
                    status        VARCHAR(255) NOT NULL,
                    error_message TEXT,
                    created_at    DATETIME    NOT NULL,

                    INDEX idx_event_outbox_status_event (event_id),
                    INDEX idx_event_outbox_status_event_created (event_id, created_at DESC)
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
