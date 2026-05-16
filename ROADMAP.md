# Roadmap

Known limitations and planned work. The transactional outbox, per-listener
redelivery, ignored-exceptions skip-list and stuck-events recovery are shipped
and stable. Everything below is intentionally deferred until real usage shapes
the design — items are listed in roughly the order we expect to need them.

## Operational concerns

These matter once the library is running in production for a while.

### Audit table pruning

`event_outbox_status` appends a row per status transition and never shrinks.
`event_outbox_redelivery` keeps `succeeded` and `failed` rows forever. At
modest volume this is fine — at scale the audit table is the first thing to
get heavy.

**Likely shape:** `SqlEventStore::pruneAuditRowsOlderThan(CarbonInterval $age,
int $batchSize = 1000): int` and a matching method on the redelivery tracker.
Called from a daily cron. Deletes in bounded batches to keep the lock window
short.

**Trigger:** when the audit table crosses some millions of rows or shows up
in slow-query logs.

### Metric / observability hooks

There's no first-class way to observe queue depth, dispatch latency, retry
rates, or recovered-stuck-event counts. Users have to write their own SQL or
wrap the processor.

**Likely shape:** a metering decorator on `ListenerDispatcher` (records
dispatch counts and latency per listener) and one on `EventProcessor` /
`RedeliveryProcessor` (records batch size and duration). Same composition
pattern as `SilentEventProcessor` and `RedeliveringListenerDispatcher` — no
new interface needed. Users plug StatsD / OpenTelemetry / Prometheus
backends in from there.

**Trigger:** first time an oncall needs to answer "is the outbox keeping up?"
and there's no dashboard.

### Stack trace in `last_error`

`SqlRedeliveryStore` records the failed dispatch's error as
`ExceptionClass: message`. Useful for inspection but loses the throw site.
For permanent failures discovered weeks later, the missing context bites.

**Likely shape:** widen `last_error` to include either the first stack frame
(`Class: message at File:Line`) or a serialised trace (TEXT → MEDIUMTEXT, JSON
column on MySQL).

**Trigger:** first post-mortem where a permanently-failed row is unreadable.

### Schema migrations

Initial-table installation is no longer automatic — host applications either
run the shipped DDL templates (`migrations/{mysql,sqlite}/`) through their own
migration tool, or call `Schema::create()` at boot. That covers v1 cleanly, but
there's still no story for evolving the schema once it's live. Adding a column
(e.g. `claimed_until` for a lease-based redelivery claim) needs ordered, idempotent
ALTERs across every installation.

**Likely shape:** ship each schema change as a new numbered file in `migrations/`
(e.g. `0002_add_claimed_until.sql` per driver). Host migration tools pick up new
files the same way they handled the initial create — one host migration per
shipped file. For projects on the `Schema::create()` path, add an optional
`Schema::migrate(PDO)` helper that tracks applied versions in a `schema_version`
table and runs the same DDL files idempotently.

**Trigger:** the first time we need to change an existing column.

## Scale concerns

These matter once volume grows past a single moderate-sized service.

### Batch claim and update

Both `SqlEventStore::next()` and `SqlRedeliveryStore::next()` claim one row
per call inside their own transaction; `markProcessed()` and
`update()` each write back in their own transaction too. The processor loops
internally until empty, so 10k due rows means ~20k tiny transactions per
worker pass.

**Likely shape:** `next(int $batchSize): list<RawEvent>` / `list<Redelivery>`
that claim N rows in one transaction, and a matching batched write-back. The
processor loop becomes "claim batch, dispatch each, write batch back."

**Trigger:** first time a worker can't drain the queue at its current
cadence, or first time the per-transaction overhead shows up in DB load
graphs.

### Per-aggregate ordering

The processor dispatches events ordered by `publish_at`. Multiple events for
the same aggregate (same order, same user) can interleave across workers. For
many domains this is fine — for some (state transitions on the same entity)
it isn't.

**Likely shape:** optional `partition_key` column on `event_outbox`. `next()`
prefers rows whose partition isn't already being processed. Either via a
distributed lock per partition or by including the partition in the claim
predicate.

**Trigger:** first incident caused by two events for the same entity firing
out of order.

### Idempotency tokens

The library expects listeners to be idempotent (especially after a stuck-event
recovery re-dispatch) but doesn't help them be idempotent. A typical
production pattern: hand each dispatch a unique key that listeners can persist
in a dedup table to short-circuit duplicates.

**Likely shape:** pass an `idempotency_key` to listeners alongside the
hydrated event (e.g. `event.{event_id}.listener.{listener_class}.attempt.{n}`
). Optional table + helper for storing/checking it.

**Trigger:** first listener that can't safely re-execute (payment capture,
external API call without its own dedup).

## Ergonomics

### Per-listener retry policies

The processor uses a single global `RetryPolicy`. Some listeners deserve
aggressive retries (billing, critical workflows); others should fail fast
(best-effort notifications). Spring Modulith supports per-handler policies
via annotations.

**Likely shape:** `EventSubscriberMap` already maps name → callable[]. Extend
to map (name, listener) → RetryPolicy with a default fallback.

**Trigger:** first time a fail-fast listener pollutes redelivery with rows
that will never succeed.

## Reference reading

- [Spring Modulith — Event Publication Registry](https://docs.spring.io/spring-modulith/reference/events.html) — closest reference shape (per-listener rows, status enum including `PROCESSING` / `RESUBMITTED`, completion attempts).
- [gruelbox/transaction-outbox](https://github.com/gruelbox/transaction-outbox) — alternative model with no `processing` state (uses optimistic-lock `version` column + `nextAttemptTime` lease). Worth understanding for context on why our model needs `processing` and theirs doesn't (their event = single dispatch; ours = multiple listeners).
- [AWS Prescriptive Guidance: Transactional Outbox](https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html) — high-level pattern doc.
