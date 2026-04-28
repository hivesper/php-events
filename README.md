# ☀️ php-events

A lightweight, framework-agnostic event system for PHP 8.4+ built around the **transactional outbox pattern**.

Events are first written to a durable store (in-memory or SQL), then dispatched to subscribers by a processor. This decouples publishing from handling and makes event delivery reliable across process boundaries.

---

## Features

- **Publish events** with a typed payload, a string `name`, and an optional `publishAt` timestamp
- **Subscribe** to event types with any callable
- **Process** queued events sequentially — each event is routed to every registered subscriber by type
- **Two stores out of the box** — in-memory for tests/dev, SQL (MySQL / SQLite) for production
- **Two processors out of the box** — standard (fail-fast for local/CI) and silent (log-and-continue for production)
- **Outbox pattern** — events transition `pending → processing → processed`, with the intermediate state surviving worker crashes
- **Per-listener retries** — configurable `RetryPolicy` plus a hybrid in-process + persisted `RedeliveryTracker`; a single failing listener of an event is retried independently while the others continue
- **Ignored exceptions** — pass a list of `Throwable` classes to silently swallow expected domain failures (no retry, no log, no DB row)
- **Status audit trail** — every event status transition is recorded in `event_outbox_status` for ops visibility
- **Scheduled delivery** — set `publishAt` in the future; the processor only picks up events whose time has come
- **Worker-safe** — MySQL store uses `FOR UPDATE SKIP LOCKED` to allow multiple workers without double-processing
- **Auto schema** — `SqlEventStore` and `SqlRedeliveryTracker` each create their own tables on first boot, no migrations needed
- **Clean architecture boundaries** — `EventSerializer` and `EventHydrator` keep `RawEvent` out of your application layer

---

## Installation

```bash
composer require hivesper/php-events
```

> Requires PHP ≥ 8.4, `ext-json`, `ext-pdo`.

---

## Core concepts

```
┌──────────────────┐  serialize()  ┌─────────────────┐   add()   ┌───────────────┐   next()  ┌───────────────────────┐
│  Domain Event    │──────────────▶│ EventSerializer │──────────▶│   EventStore  │──────────▶│  EventProcessor       │
└──────────────────┘               └─────────────────┘           └───────────────┘           │  (reads + dispatches) │
                                   (via EventPublisher)                                      └───────────┬───────────┘
                                                                                                         │ hydrate() + of(name)
                                                                                             ┌───────────▼───────────┐
                                                                                             │    EventSubscriber    │
                                                                                             │   (holds callables)   │
                                                                                             └───────────────────────┘
```

| Class | Role |
|---|---|
| `RawEvent` | Immutable value object representing a single stored event |
| `EventStore` | Interface — a durable queue of `RawEvent`, with `add()` / `next()` / `markProcessed()` |
| `EventSerializer` | Interface — converts a domain event object into a `SerializedEvent` |
| `EventHydrator` | Interface — reconstructs a domain event object from a stored name + payload |
| `SerializedEvent` | Value object holding the event `name` and `payload` array |
| `EventPublisher` | Serializes a domain event and pushes it into the store, returning its ID |
| `EventSubscriberMap` | Registry of `name → callable[]` mappings |
| `EventProcessor` | Interface — drains the store and dispatches to subscribers |
| `SequentialEventProcessor` | Built-in processor — dispatches events one by one with optional retry policy; exceptions propagate after retries are exhausted (fail-fast) |
| `SilentSequentialEventProcessor` | Same as above but catches per-listener failures after retries are exhausted, logs them via PSR-3, and continues |
| `EventSubscriberBuilder` | Fluent builder that produces a ready-to-use `EventSubscriberMap` |
| `RetryPolicy` | Interface — decides whether and when to retry a failed listener |
| `NoRetryPolicy` | Default — never retries |
| `ExponentialBackoffRetryPolicy` | Built-in — five attempts with `100ms / 500ms / 1min / 5min` backoff |
| `RedeliveryTracker` | Interface — persists per-(event, listener) retry state and exposes `retryNow()` for admin tooling |
| `InMemoryRedeliveryTracker` / `SqlRedeliveryTracker` | Tracker implementations |

---

## Quick start

```php
use Tcds\Io\Raw\EventPublisher;
use Tcds\Io\Raw\EventSubscriberMap;
use Tcds\Io\Raw\Infrastructure\InMemoryEventStore;
use Tcds\Io\Raw\Infrastructure\JacksonSerializer;
use Tcds\Io\Raw\Infrastructure\SequentialEventProcessor;

// 1. Define a typed domain event
final readonly class OrderPlaced
{
    public function __construct(
        public int $orderId,
        public float $total,
    ) {}
}

// 2. Wire up the store, publisher, and processor
$store      = new InMemoryEventStore();
$publisher  = new EventPublisher($store, new JacksonSerializer());

$subscribers = new EventSubscriberMap();
$processor   = new SequentialEventProcessor($subscribers); // JacksonHydrator is the default

// 3. Register subscribers — type-hint the domain event class to receive it fully hydrated
$subscribers->subscribe('OrderPlaced', function (OrderPlaced $event): void {
    echo "Order placed: " . $event->orderId . PHP_EOL;
});

$subscribers->subscribe('OrderPlaced', function (OrderPlaced $event): void {
    echo "Sending confirmation email..." . PHP_EOL;
});

// 4. Publish a domain event
$publisher->publish(new OrderPlaced(orderId: 42, total: 99.99));

// 5. Process — both subscribers fire in registration order
$processor->process($store);

// Output:
// Order placed: 42
// Sending confirmation email...
```

---

## RawEvent

`RawEvent` is an internal value object used by the store layer. Application code does not construct or receive `RawEvent` directly — use `EventSerializer` when publishing and `EventHydrator` when processing (see below).

Events are created via two static factories:

```php
// Create a brand-new event (generates a UUID v7 id, sets status → pending)
$event = RawEvent::create(
    name: 'payment.received',
    payload: ['amount' => 150, 'currency' => 'USD'],
    publishAt: CarbonImmutable::now(),
);

echo $event->id;        // uuid7 string
echo $event->name;      // "payment.received"
echo $event->status;    // RawEventStatus::pending
print_r($event->payload); // ['amount' => 150, 'currency' => 'USD']
```

```php
// Reconstruct an event from persisted data (used internally by SqlEventStore)
$event = RawEvent::retrieve(
    id: $row['id'],
    name: $row['name'],
    status: RawEventStatus::from($row['status']),
    payload: json_decode($row['payload'], true),
    createdAt: new CarbonImmutable($row['created_at']),
    publishAt: new CarbonImmutable($row['publish_at']),
);
```

### Scheduled events

Pass any `CarbonImmutable` timestamp as `publishAt` — the SQL store only dequeues events whose `publish_at <= now()`:

```php
$publisher->publish(
    new SubscriptionReminder(userId: 7),
    publishAt: CarbonImmutable::now()->addDays(3),
);
```

---

## EventSerializer

`EventSerializer` converts a domain event object into a `SerializedEvent` (a `name` string and a `payload` array) before it is stored. `EventPublisher` calls it automatically — application code never touches `RawEvent` directly.

```php
interface EventSerializer
{
    public function serialize(object $event): SerializedEvent;
}
```

### JacksonSerializer *(built-in)*

Derives the event name from the short class name (PascalCase) and uses Jackson's `ArrayObjectMapper` to serialize the object to an array payload. Handles constructor-promoted properties, nested objects, and collections automatically:

```php
use Tcds\Io\Raw\Infrastructure\JacksonSerializer;

$publisher = new EventPublisher($store, new JacksonSerializer());

// OrderPlaced { orderId: 42, total: 99.99 }
// → SerializedEvent { name: 'OrderPlaced', payload: ['orderId' => 42, 'total' => 99.99] }
```

> **Warning:** Renaming the class silently changes the event name, breaking any consumers subscribed to the old name. Use a custom `EventSerializer` with explicit, stable names when this matters across deployments.

### Custom serializer

Implement `EventSerializer` for explicit name mapping or complex payload graphs:

```php
use Tcds\Io\Raw\EventSerializer;
use Tcds\Io\Raw\SerializedEvent;

final class AppEventSerializer implements EventSerializer
{
    public function serialize(object $event): SerializedEvent
    {
        return match (true) {
            $event instanceof OrderPlaced => new SerializedEvent(
                name: 'order.placed',
                payload: ['order_id' => $event->orderId, 'total' => $event->total],
            ),
            // ...
            default => throw new \InvalidArgumentException('Unknown event: ' . $event::class),
        };
    }
}
```

---

## EventHydrator

`EventHydrator` reconstructs a domain event object from the stored `name` and `payload`. `SequentialEventProcessor` calls it once per subscriber, passing the subscriber as the third argument so the hydrator can resolve a different type for each listener.

```php
interface EventHydrator
{
    /** @param array<string, mixed> $payload */
    public function hydrate(string $name, array $payload, callable|string $subscriber): object;
}
```

### JacksonHydrator *(built-in, default)*

Inspects the subscriber's first parameter type-hint via reflection and delegates reconstruction to Jackson's `ArrayObjectMapper`:

- **Typed class** (`OrderPlaced $event`) — maps the payload array to a fully hydrated instance of that class, including nested objects
- **`object` or no type-hint** — falls back to a plain `stdClass` cast of the payload

Because reconstruction is driven by each subscriber's own type-hint, different listeners for the same event can each receive a different type with no extra wiring:

```php
// JacksonHydrator is the default — no explicit argument needed
$processor = new SequentialEventProcessor($subscribers);

// Typed subscriber receives a fully mapped OrderPlaced instance
$subscribers->subscribe('order.placed', function (OrderPlaced $event): void {
    echo $event->orderId; // int, not a stdClass property
});

// Untyped subscriber receives a generic stdClass
$subscribers->subscribe('order.placed', function (object $event): void {
    echo $event->orderId; // stdClass property
});
```

### Custom hydrator

Implement `EventHydrator` to return fully typed domain event objects to your subscribers:

```php
use Tcds\Io\Raw\EventHydrator;

final class AppEventHydrator implements EventHydrator
{
    public function hydrate(string $name, array $payload, callable|string $subscriber): object
    {
        return match ($name) {
            'order.placed' => new OrderPlaced(
                orderId: $payload['order_id'],
                total: $payload['total'],
            ),
            // ...
            default => throw new \InvalidArgumentException('Unknown event: ' . $name),
        };
    }
}
```

With a custom hydrator, subscribers receive typed objects:

```php
$subscribers->subscribe('order.placed', function (OrderPlaced $event): void {
    echo "Order placed: " . $event->orderId . PHP_EOL;
});

$processor = new SequentialEventProcessor($subscribers, hydrator: new AppEventHydrator());
```

---

## Event stores

### InMemoryEventStore

Zero-dependency, FIFO queue. Perfect for tests and single-process applications.

```php
$store = new InMemoryEventStore();
```

### SqlEventStore

Production-ready persistent store. Requires a PDO connection to **MySQL** or **SQLite**. Creates the `event_outbox` table automatically on first boot.

```php
use Tcds\Io\Raw\Infrastructure\SqlEventStore;

$pdo   = new PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass');
$store = new SqlEventStore($pdo); // schema created here if not present
```

**Schema created automatically:**

```sql
CREATE TABLE event_outbox (
    id         VARCHAR(36)  NOT NULL PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    status     VARCHAR(255) NOT NULL,  -- 'pending' | 'processing' | 'processed' | 'failed'
    payload    JSON         NOT NULL,
    created_at DATETIME(6)  NOT NULL,
    publish_at DATETIME(6)  NOT NULL,
    INDEX idx_event_outbox_status_publish (status, publish_at),
    INDEX idx_event_outbox_created_at (created_at)
);

-- Audit trail: one row per event status transition (pending → processing → processed)
CREATE TABLE event_outbox_status (
    event_id      VARCHAR(36)  NOT NULL,
    status        VARCHAR(255) NOT NULL,
    error_message TEXT,
    created_at    DATETIME(6)  NOT NULL,
    INDEX idx_event_outbox_status_event_created (event_id, created_at DESC)
);
```

> MySQL workers use `SELECT … FOR UPDATE SKIP LOCKED` on the `event_outbox` table for safe
> concurrent processing.

If you wire up a `RedeliveryTracker` (see [Automatic retry & failure tracking](#automatic-retry--failure-tracking)),
a third table `event_outbox_redelivery` is created on demand for per-listener retry state.

### Event lifecycle

The processor advances each event through three states:

1. `pending` — written by `add()` inside the caller's transaction, alongside a matching audit row.
2. `processing` — set by `next()` when the worker claims the event. The row is *claimed* but not
   yet declared finished. The audit table gets a second row.
3. `processed` — set by `markProcessed()` once **every** listener for the event has settled
   (succeeded, been persisted to the redelivery queue, been swallowed by the ignored-exceptions
   list, or been marked permanently failed). The audit table gets a third row.

If a worker dies between `next()` and `markProcessed()`, the row stays in `processing` —
intentionally. Any redelivery rows that *did* get persisted before the crash remain durable, so
listener-level retries still fire when their time comes. The stuck `processing` row is detectable
by querying `event_outbox_status` (see [Future work](#future-work)).

---

## EventSubscriberMap

Subscribe any callable to a named event type:

```php
$subscribers = new EventSubscriberMap();

// Closure
$subscribers->subscribe('order.cancelled', function (object $event): void {
    // ...
});

// First-class callable syntax
$subscribers->subscribe('order.shipped', $myService->onOrderShipped(...));

// Class name string — must implement __invoke(); instantiated by DefaultHandlerResolver
$subscribers->subscribe('order.placed', OrderPlacedHandler::class);

// Pre-populate via constructor (useful for DI containers)
$subscribers = new EventSubscriberMap([
    'order.placed' => [$listenerA, $listenerB],
    'payment.failed' => [$alertHandler],
]);
```

Multiple subscribers for the same type are called **in registration order**.

---

## EventSubscriberBuilder

A fluent builder that produces a ready-to-use `EventSubscriberMap`. Useful for wiring up callables in one place before handing the result to `SequentialEventProcessor`:

```php
use Tcds\Io\Raw\EventSubscriberBuilder;

$subscribers = EventSubscriberBuilder::create()
    ->eventType('order.placed',     [OrderPlacedHandler::class, AuditLogger::class])
    ->eventType('payment.received', [PaymentHandler::class])
    ->listener(NotificationService::class, types: ['order.placed', 'order.shipped'])
    ->build();

$processor = new SequentialEventProcessor($subscribers);
$processor->process($store);
```

Duplicate listener registrations are deduplicated automatically.

---

## Running your own processor

`SequentialEventProcessor` processes all currently-queued events in a single call. Run it in a scheduled job, queue worker, or after each HTTP request:

```php
// In a console command / cron / queue worker:
$processor->process($store);
```

Implement `EventProcessor` to build your own — e.g. a parallel or batched processor:

```php
use Tcds\Io\Raw\EventProcessor;
use Tcds\Io\Raw\EventStore;

class MyProcessor implements EventProcessor
{
    public function process(EventStore $store): void
    {
        while ($event = $store->next()) {
            // your dispatch logic
            $store->markProcessed($event->id);
        }
    }
}
```

> Custom processors must call `$store->markProcessed($event->id)` once dispatch for an event is
> complete. `next()` only moves the row from `pending` to `processing`; the final advance to
> `processed` is the processor's responsibility, so it can hold the row in `processing` while it
> drives any per-listener retries.

### Custom EventStore

If you implement your own `EventStore`, you need to satisfy the new `markProcessed()` method
alongside `add()` and `next()`. For an in-memory or queue-style store with no persisted status,
this is a one-liner:

```php
class MyEventStore implements EventStore
{
    public function add(RawEvent $event): void { /* ... */ }
    public function next(): ?RawEvent           { /* ... */ }
    public function markProcessed(string $eventId): void
    {
        // No-op when there's no persisted status to flip.
    }
}
```

---

## Silent event processing

In local development and CI, letting a failing listener throw immediately is exactly what you want — fast, noisy feedback. In production the calculus is different: a single listener failure should not prevent the remaining listeners for that event from running, nor should it block every subsequent event in the queue. `SilentSequentialEventProcessor` wraps each listener dispatch in a try-catch, logs the failure, and carries on.

```php
use Tcds\Io\Raw\Infrastructure\SilentSequentialEventProcessor;

$processor = new SilentSequentialEventProcessor($subscribers, $logger);
$processor->process($store);
```

Each failure produces exactly one log entry per event–listener pair, so nothing is silently swallowed. Every exception is recorded with enough context to diagnose and replay the specific listener later:

```
Failed to dispatch event to listener.
{
    event:     "order.placed"
    event_id:  "019612a3-..."
    listener:  "App\Listeners\SendConfirmationEmail"  // or "Closure"
    exception: RuntimeException: ...
}
```

### PSR-3 logger

`SilentSequentialEventProcessor` accepts any [PSR-3](https://www.php-fig.org/psr/psr-3/) `LoggerInterface`. vesper php-events ships no concrete logger — supply whichever one your application already uses:

```php
// Monolog
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('events');
$logger->pushHandler(new StreamHandler('php://stderr'));

$processor = new SilentSequentialEventProcessor($subscribers, $logger);
```

```php
// Laravel (already PSR-3 compatible)
$processor = new SilentSequentialEventProcessor($subscribers, app('log'));
```

### Choosing a processor per environment

| Environment | Processor | Behaviour |
|---|---|---|
| Local / CI | `SequentialEventProcessor` | Any listener exception propagates immediately — nothing is hidden |
| Production | `SilentSequentialEventProcessor` | A failing listener is logged and skipped; all other listeners and subsequent events continue processing |

Both processors support [Automatic retry & failure tracking](#automatic-retry--failure-tracking)
through the same constructor parameters. The retry policy and the redelivery tracker are opt-in:
default behaviour for both processors is unchanged from earlier versions.

---

## Automatic retry & failure tracking

A failed listener should not become a silent loss. This library supports automatic retry and
durable failure tracking on a **per-(event, listener)** basis: if a single listener of an event
fails, only that listener is retried — the others continue to run and successful deliveries are
not re-fired.

The model is **hybrid**:

1. A small in-process retry burst (sleep + retry) for fast-recovery transient failures.
2. A persisted, scheduled redelivery for slower-recovery failures and for surviving worker crashes.

Once configured, the same retry behaviour applies to both `SequentialEventProcessor` (which
fails fast after retries are exhausted) and `SilentSequentialEventProcessor` (which logs and
continues).

### Retry policy

A `RetryPolicy` decides whether a failed dispatch should be retried, and at what time:

```php
use Vesper\Tool\Event\Retry\RetryPolicy;

interface RetryPolicy
{
    /** @return CarbonImmutable|null  null when no further retries should be made */
    public function nextRetryAt(int $previousAttempt): ?CarbonImmutable;
}
```

Two implementations ship out of the box:

- **`NoRetryPolicy`** *(default)* — never retries. Same behaviour as before this feature existed,
  so wiring up the new processor parameters without choosing a policy is a no-op.
- **`ExponentialBackoffRetryPolicy`** — five total attempts (one initial + four retries) with
  delays of `100ms, 500ms, 1min, 5min` by default. The 100ms / 500ms retries happen in-process;
  the 1min / 5min retries are persisted and picked up on a future processor run.

```php
use Vesper\Tool\Event\Infrastructure\Retry\ExponentialBackoffRetryPolicy;

// Default delays — 100ms, 500ms, 1min, 5min.
$retryPolicy = new ExponentialBackoffRetryPolicy();

// Or roll your own delays:
$retryPolicy = new ExponentialBackoffRetryPolicy(delaysMs: [50, 250, 1_000, 30_000]);
```

The processor classifies each delay as **in-process** (sleep + retry on the same worker) when it
is less than `inProcessRetryThresholdMs` (default `1000`), and **persisted** otherwise. Persisted
retries require a `RedeliveryTracker` to be configured — without one, a long-delay retry is
treated as exhausted and reported.

### Redelivery tracker

The `RedeliveryTracker` interface owns per-listener retry state — when an attempt failed, how
many attempts have been made, when the next one should run, what the last error was. Two
implementations:

- **`InMemoryRedeliveryTracker`** — array-backed, for tests and dev.
- **`SqlRedeliveryTracker`** — durable, MySQL/SQLite-compatible. Auto-creates its
  `event_outbox_redelivery` table on first construction. Worker-safe via `FOR UPDATE SKIP LOCKED`
  on MySQL.

```php
use Vesper\Tool\Event\Infrastructure\SqlRedeliveryTracker;

$tracker = new SqlRedeliveryTracker($pdo); // schema created here if not present
```

### Wiring a processor with retries

```php
use Vesper\Tool\Event\Infrastructure\Retry\ExponentialBackoffRetryPolicy;
use Vesper\Tool\Event\Infrastructure\SilentSequentialEventProcessor;
use Vesper\Tool\Event\Infrastructure\SqlRedeliveryTracker;

$processor = new SilentSequentialEventProcessor(
    subscribers:        $subscribers,
    logger:             $logger,
    retryPolicy:        new ExponentialBackoffRetryPolicy(),
    redeliveryTracker:  new SqlRedeliveryTracker($pdo),
    ignoredExceptions:  [
        UserNotFoundException::class,
        InvalidPayloadException::class,
    ],
);

$processor->process($store);
```

A single call to `process()` will:

1. Drain new pending events from the store and dispatch them to every registered listener.
2. After the main queue is empty, drain any **due** redeliveries — listener failures from earlier
   runs whose `next_retry_at` has now passed.

Long backoffs (e.g. the default 1min / 5min steps) won't be drained until a future `process()`
call after their `next_retry_at` passes — which is exactly how outbox workers already poll on a
schedule.

### Ignored exceptions (skip-list)

Some listener failures are not bugs — they're expected domain outcomes that the application
already handles upstream (e.g. `UserNotFoundException`, `OrderAlreadyShipped`). Retrying them
wastes time and reporting them spams the error tracker.

The `ignoredExceptions` constructor parameter takes a list of `Throwable` class-strings.
Matching is `instanceof`-based, so subclasses are also matched. When a listener throws an
ignored exception:

- **No retry attempt** — the policy is skipped entirely.
- **No PSR-3 log line** (in `SilentSequentialEventProcessor`).
- **No row written to `event_outbox_redelivery`.**
- **No exception propagation** in `SequentialEventProcessor` either — this is treated as
  silent success, the next listener for the same event runs as normal.

The recommended pattern is to share the same list with whatever already configures your
application's error reporter (Sentry/Bugsnag/etc.) so behaviour stays consistent across the
boundary: anything your app considers "expected and not worth a page" is also considered
expected here.

### Permanently failed dispatches

When a listener has exhausted its retries (or `nextRetryAt` returns `null` immediately because
no retry policy is configured), it is recorded in `event_outbox_redelivery` with
`status = 'failed'`. The row stays in the table so operators can inspect it. The behaviour
differs slightly between processors:

| Processor | On exhaustion |
|---|---|
| `SequentialEventProcessor` | Marks the row `failed`, then **rethrows** the original exception (fail-fast). The event row stays in `processing` — no `markProcessed()` is called. |
| `SilentSequentialEventProcessor` | Marks the row `failed`, **logs** via PSR-3 (same shape as today), and **swallows** the exception so the next listener runs. The event eventually advances to `processed`. |

### Re-triggering a failed dispatch

`RedeliveryTracker::retryNow($eventId, $listener)` re-queues a dispatch for immediate retry,
regardless of its current status (including `failed`). The attempt count is preserved — the
retry policy's max-attempts ceiling still applies on subsequent automatic failures. The library
ships no CLI; wire `retryNow()` into whatever admin surface you prefer (admin UI, Slack
command, console script, etc.).

For listing failures, query `event_outbox_redelivery` directly.

### Listener identity and closures

The redelivery row's `listener` column is the class name for class-string subscribers, the
class name for invokable objects (`get_class($obj)`), or the literal string `'Closure'` for
anonymous closures. Class-string and invokable-object listeners can be reliably retried across
processes; closures cannot (their identity is not stable across process boundaries). If your
listener registrations and your retry policy together require closure tracking, use a class
that implements `__invoke()` instead.

---

## RawEventStatus

```php
RawEventStatus::pending     // event is waiting to be processed
RawEventStatus::processing  // event has been claimed by a worker; dispatch in flight
RawEventStatus::processed   // event was successfully dispatched to all listeners
RawEventStatus::failed      // reserved for future event-level fatal use (see "Future work")
```

---

## Future work

This section captures known gaps and what we'd want to add. The redelivery layer is the headline
feature shipped now; what follows is intentionally deferred so we can let real usage shape it.

### Stuck-events monitor

If a worker dies between `next()` and `markProcessed()`, the row stays in
`event_outbox.status = 'processing'` indefinitely. Redelivery rows that *did* get persisted are
durable and will still fire — but the parent event row is wedged.

Detection query (works today against existing tables):

```sql
SELECT id, name, created_at, publish_at
FROM event_outbox
WHERE status = 'processing'
  AND id IN (
    SELECT event_id FROM event_outbox_status
    WHERE status = 'processing'
      AND created_at < NOW() - INTERVAL 30 MINUTE  -- pick a threshold that matches your workload
  );
```

A future sweeper should support three recovery modes:

- **Re-claim** (`processing → pending`) — safe **only** if listeners are idempotent. Re-dispatch
  may re-fire listeners that already succeeded.
- **Force-complete** (`processing → processed`) — safe only when the dispatch is believed to
  have finished but the bookkeeping commit was lost. Should write a `processed` audit row marked
  as recovered so dashboards can distinguish organic vs. recovered transitions.
- **Mark dead** (`processing → failed`) — finally puts `RawEventStatus::failed` to use; operator
  decides next steps.

### Schema columns to consider when the monitor lands

Based on Spring Modulith's `EVENT_PUBLICATION` table (the closest production-grade analogue):

| Column | Why we'd want it |
|---|---|
| `completion_attempts` (INT) on `event_outbox` | How many times this event has been claimed for processing. Increments on every `next()`. Distinguishes "stuck once" from "repeatedly poisoning workers". |
| `last_resubmission_date` (TIMESTAMP) on `event_outbox` | Distinguishes organic stuck rows from rows an operator has already touched. |
| A `RESUBMITTED` enum case (or a `recovered_at` / `recovered_by` column on `event_outbox_status`) | So dashboards can show "rescued by hand" separately from happy path. |

The existing `event_outbox_redelivery` table already tracks `attempt_number` and `last_error` at
the *listener* level; the columns above are for *event-level* tracking, which we don't need yet
but will once the monitor lands.

### Operational signals you can build today

Without waiting for the in-library monitor, these are queryable from the existing tables:

- "Events stuck in `processing` for more than T" — the SQL above.
- "Listeners with permanent failures" — `SELECT * FROM event_outbox_redelivery WHERE status = 'failed'`.
- "Average time-between rows in `event_outbox_status` by status pair" — reveals dispatch latency.

### What `RawEventStatus::failed` is reserved for

A natural future use: a sweep over `event_outbox_redelivery` that, when **every** listener for
an event has permanently failed and there's nothing left to retry, marks the event row itself
`failed`. Computable from the existing tables; out of scope for this PR. The enum case is
documented as reserved so future readers don't think it's dead code.

### Reference reading

- [Spring Modulith — Event Publication Registry](https://docs.spring.io/spring-modulith/reference/events.html) — closest reference shape (per-listener rows, status enum including `PROCESSING` / `RESUBMITTED`, completion attempts).
- [gruelbox/transaction-outbox](https://github.com/gruelbox/transaction-outbox) — alternative model with no `processing` state (uses optimistic-lock `version` column + `nextAttemptTime` lease). Worth understanding for context on why our model needs `processing` and theirs doesn't (their event = single dispatch; ours = multiple listeners).
- [AWS Prescriptive Guidance: Transactional Outbox](https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html) — high-level pattern doc.

---

## Testing

```bash
composer test:unit     # unit tests only
composer test:feature  # feature tests (SQLite in-memory)
composer test:stan     # PHPStan at level max
composer test:cs       # code style check
```

Or run everything:

```bash
composer tests
```

---

## License

MIT — see [LICENSE](LICENSE).
