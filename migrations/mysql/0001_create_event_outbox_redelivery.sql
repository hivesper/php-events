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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
