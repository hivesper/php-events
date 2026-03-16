<?php

namespace Tcds\Io\Ray\Infrastructure\Schema;

use PDO;
use PDOException;

class SqliteEventStoreSchema
{
    /**
     * Ensure the outbox tables and indexes exist
     *
     * @throws PDOException
     */
    public static function create(PDO $connection): void
    {
        $connection->exec(
            <<<SQL
                CREATE TABLE IF NOT EXISTS event_outbox (
                    id         TEXT NOT NULL PRIMARY KEY,
                    name       TEXT NOT NULL,
                    status     TEXT NOT NULL,
                    payload    TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    publish_at TEXT NOT NULL
                )
            SQL,
        );

        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_event_outbox_status_publish
             ON event_outbox (status, publish_at)',
        );

        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_event_outbox_created_at
             ON event_outbox (created_at)',
        );

        $connection->exec(
            <<<SQL
                CREATE TABLE IF NOT EXISTS event_outbox_status (
                    event_id      TEXT NOT NULL,
                    status        TEXT NOT NULL,
                    error_message TEXT,
                    created_at    TEXT NOT NULL
                )
            SQL,
        );

        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_event_outbox_status_event
             ON event_outbox_status (event_id)',
        );

        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_event_outbox_status_event_created
             ON event_outbox_status (event_id, created_at)',
        );
    }
}
