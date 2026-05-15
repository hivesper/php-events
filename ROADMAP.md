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

**Likely shape:** a small `EventProcessorObserver` interface with hooks like
`onDispatched`, `onScheduledForRetry`, `onPermanentlyFailed`, `onRecovered`.
Default no-op implementation; users plug in StatsD / OpenTelemetry /
Prometheus / whatever from there.

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

Auto-create-on-construct is great for first boot but provides no path for
schema evolution. Adding a column (e.g. `claimed_until` for a lease-based
redelivery claim) won't backfill existing installations.

**Likely shape:** a `schema_version` column on each table + a
`migrate()` method that runs idempotent ALTERs ordered by version.

**Trigger:** the first time we need to change an existing column.

## Scale concerns

These matter once volume grows past a single moderate-sized service.

### Batch redelivery

`processNextRedelivery()` drains one row per call. For a backlog of 10k
retries, that's 10k separate transactions and lock acquisitions.

**Likely shape:** `processDueRedeliveries(int $batchSize): int` that wraps a
configurable number of dispatches in one transaction (or one transaction per
row but in a tight loop, sharing the connection).

**Trigger:** first time the cron task can't keep up at its current cadence.

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
