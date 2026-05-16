CREATE TABLE event_outbox (
    id           VARCHAR(36)  NOT NULL PRIMARY KEY,
    name         VARCHAR(255) NOT NULL,
    status       VARCHAR(255) NOT NULL,
    payload      JSON         NOT NULL,
    created_at   DATETIME(6)  NOT NULL,
    publish_at   DATETIME(6)  NOT NULL,

    INDEX idx_event_outbox_status_publish (status, publish_at),
    INDEX idx_event_outbox_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE event_outbox_status (
    event_id      VARCHAR(36) NOT NULL,
    status        VARCHAR(255) NOT NULL,
    error_message TEXT,
    created_at    DATETIME(6) NOT NULL,

    INDEX idx_event_outbox_status_event_created (event_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
