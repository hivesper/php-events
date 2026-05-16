CREATE TABLE event_outbox_redelivery (
    event_id        TEXT    NOT NULL,
    listener        TEXT    NOT NULL,
    status          TEXT    NOT NULL,
    attempt_number  INTEGER NOT NULL,
    next_retry_at   TEXT    NOT NULL,
    last_error      TEXT,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL,
    PRIMARY KEY (event_id, listener)
);

CREATE INDEX idx_redelivery_due
    ON event_outbox_redelivery (status, next_retry_at);

CREATE INDEX idx_redelivery_event_id
    ON event_outbox_redelivery (event_id);
