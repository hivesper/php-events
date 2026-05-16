CREATE TABLE event_outbox (
    id         TEXT NOT NULL PRIMARY KEY,
    name       TEXT NOT NULL,
    status     TEXT NOT NULL,
    payload    TEXT NOT NULL,
    created_at TEXT NOT NULL,
    publish_at TEXT NOT NULL
);

CREATE INDEX idx_event_outbox_status_publish
    ON event_outbox (status, publish_at);

CREATE INDEX idx_event_outbox_created_at
    ON event_outbox (created_at);

CREATE TABLE event_outbox_status (
    event_id      TEXT NOT NULL,
    status        TEXT NOT NULL,
    error_message TEXT,
    created_at    TEXT NOT NULL
);

CREATE INDEX idx_event_outbox_status_event
    ON event_outbox_status (event_id);

CREATE INDEX idx_event_outbox_status_event_created
    ON event_outbox_status (event_id, created_at);
