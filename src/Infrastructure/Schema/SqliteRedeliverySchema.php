<?php

namespace Vesper\Tool\Event\Infrastructure\Schema;

use PDO;
use PDOException;

class SqliteRedeliverySchema
{
    /**
     * Ensure the redelivery table and indexes exist.
     *
     * @throws PDOException
     */
    public static function create(PDO $connection): void
    {
        $connection->exec(
            <<<SQL
                CREATE TABLE IF NOT EXISTS event_outbox_redelivery (
                    event_id        TEXT NOT NULL,
                    listener        TEXT NOT NULL,
                    status          TEXT NOT NULL,
                    attempt_number  INTEGER NOT NULL,
                    next_retry_at   TEXT,
                    last_error      TEXT,
                    created_at      TEXT NOT NULL,
                    updated_at      TEXT NOT NULL,
                    PRIMARY KEY (event_id, listener)
                )
            SQL,
        );

        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_redelivery_due
             ON event_outbox_redelivery (status, next_retry_at)',
        );
    }
}
