# ☀️ php-events

A lightweight, framework-agnostic event system for PHP 8.4+ built around the **transactional outbox pattern**.

Events are first written to a durable store (in-memory or SQL), then dispatched to subscribers by a processor. This decouples publishing from handling and makes event delivery reliable across process boundaries.

---

## Features

- **Publish events** with a typed payload, a string `name`, and an optional `publishAt` timestamp
- **Subscribe** to event types with any callable
- **Process** queued events sequentially — each event is routed to every registered subscriber by type
- **Two stores out of the box** — in-memory for tests/dev, SQL (MySQL / SQLite) for production
- **Composable dispatch** — a `ListenerDispatcher` interface with a default void+throw implementation and a redelivering decorator that catches failures and writes them to a `RedeliveryStore`
- **Outbox pattern** — events transition `pending → processing → processed`, with the intermediate state surviving worker crashes
- **Per-listener retries** — failures persist to a `RedeliveryStore` and the `SequentialRedeliveryProcessor` consults a configurable `RetryPolicy` to decide whether and when to retry; a single failing listener of an event is retried independently while the others continue
- **Ignored exceptions** — pass a list of `Throwable` classes to the dispatcher to silently swallow expected domain failures (no retry, no log, no DB row)
- **Status audit trail** — every event status transition is recorded in `event_outbox_status` for ops visibility
- **Scheduled delivery** — set `publishAt` in the future; the processor only picks up events whose time has come
- **Worker-safe** — MySQL store uses `FOR UPDATE SKIP LOCKED` to allow multiple workers without double-processing
- **Auto schema** — `SqlEventStore` and `SqlRedeliveryStore` each create their own tables on first boot, no migrations needed
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
| `EventProcessor` | Interface — drains an `EventStore` and dispatches each event to its subscribers |
| `SequentialEventProcessor` | Built-in event processor — runs each subscriber via the supplied `ListenerDispatcher` and calls `markProcessed`. Anything the dispatcher throws propagates out (fail-fast for local/CI); use `RedeliveringListenerDispatcher` to route failures into a `RedeliveryStore` instead. |
| `SilentEventProcessor` | Decorator — wraps any `EventProcessor` and logs (via PSR-3) any `Throwable` that escapes `process()` instead of letting it propagate. Use in production around the whole batch. |
| `ListenerDispatcher` | Interface — invokes one (subscriber, event) pair. Returns `void`; lets the listener exception propagate. |
| `DefaultListenerDispatcher` | Built-in — resolves the handler, hydrates the payload, calls the listener. Swallows exceptions listed in `ignoredExceptions`; otherwise rethrows. Stateless and side-effect-free. |
| `RedeliveringListenerDispatcher` | Decorator — wraps any `ListenerDispatcher` and on a throw schedules a fresh row in the `RedeliveryStore` (attempt 1, retry-now). Used by the event-processing flow only — the redelivery processor must run a plain dispatcher to avoid double-scheduling. |
| `RedeliveryProcessor` | Interface — drains a `RedeliveryStore`, mirroring `EventProcessor::process()` |
| `SequentialRedeliveryProcessor` | Built-in redelivery processor — owns the `RetryPolicy` and decides per due row whether to reschedule, mark succeeded, or mark failed permanently |
| `SilentRedeliveryProcessor` | Decorator — same shape as `SilentEventProcessor` but for `RedeliveryProcessor`. |
| `EventSubscriberBuilder` | Fluent builder that produces a ready-to-use `EventSubscriberMap` |
| `RetryPolicy` | Interface — decides whether and when to retry a failed listener (consulted only by the redelivery processor) |
| `NoRetryPolicy` | Default — never retries |
| `ExponentialBackoffRetryPolicy` | Built-in — five attempts with `100ms / 500ms / 1min / 5min` backoff |
| `RedeliveryStore` | Interface — persists per-(event, listener) retry state and exposes `retryNow()` for admin tooling |
| `InMemoryRedeliveryStore` / `SqlRedeliveryStore` | `RedeliveryStore` implementations |

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

## Scheduled jobs

A production deployment runs four scheduled jobs against this library. They touch
mostly-disjoint tables and rows, so they can run in parallel without contending.

| Job | Recommended cadence | What it does |
|---|---|---|
| `$eventProcessor->process($eventStore)` | Every minute, or on demand after a business write | Drains all currently-pending events from `event_outbox` and dispatches each to its listeners. Loops internally until empty. See [Running your own processor](#running-your-own-processor). |
| `$redeliveryProcessor->process($redeliveryStore)` | Every minute | Drains all currently-due retries from `event_outbox_redelivery` (transitions each to `dispatching` and back). Loops internally until empty. See [Automatic retry & failure tracking](#automatic-retry--failure-tracking). |
| `$eventStore->recoverStuckEvents(CarbonInterval::minutes(30))` | Every 5–15 minutes | Resets `event_outbox` rows wedged in `processing` (worker crash victims) back to `pending`. Threshold should be comfortably longer than your longest healthy dispatch — see [Recovering stuck events](#recovering-stuck-events). |
| `$redeliveryStore->recoverStuckRedeliveries(CarbonInterval::minutes(30))` | Every 5–15 minutes | Resets `event_outbox_redelivery` rows wedged in `dispatching` (worker crash victims) back to `pending_retry`. Same threshold guidance as above. |

`recoverStuckEvents` and `recoverStuckRedeliveries` are SQL-specific (live on `SqlEventStore` /
`SqlRedeliveryStore`). The other two work against any `EventStore` / `RedeliveryStore` implementation.

The four jobs do not compete for the same rows:

- `EventProcessor::process()` reads `event_outbox` rows in `pending`; `recoverStuckEvents()` reads rows in `processing` — disjoint by status filter.
- `RedeliveryProcessor::process()` reads `event_outbox_redelivery` rows in `pending_retry` and claims by transitioning to `dispatching`; `recoverStuckRedeliveries()` reads rows in `dispatching` — disjoint by status filter.
- The redelivery and event tables are separate.
- The lock from `SELECT ... FOR UPDATE SKIP LOCKED` is held through each claim, so concurrent workers running the same job never claim the same row either.

The one cross-job interaction to think about: if a `recoverStuck*` sweeper resets a row while a
slow worker is still actively dispatching it, another worker can pick it up on the next tick
and re-fire its listener. Choose thresholds comfortably longer than your longest healthy
dispatch, and make sure listeners are idempotent (the at-least-once outbox contract requires
that anyway).

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

`EventHydrator` reconstructs a domain event object from the stored `name` and `payload`. `DefaultListenerDispatcher` calls it once per subscriber, passing the subscriber as the third argument so the hydrator can resolve a different type for each listener.

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

$processor = new SequentialEventProcessor(
    $subscribers,
    new DefaultListenerDispatcher(hydrator: new AppEventHydrator()),
);
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
    status     VARCHAR(255) NOT NULL,  -- 'pending' | 'processing' | 'processed'
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

If you wire up a `RedeliveryStore` (see [Automatic retry & failure tracking](#automatic-retry--failure-tracking)),
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
listener-level retries still fire when their time comes. To recover the wedged `processing` row
itself, call `SqlEventStore::recoverStuckEvents()` from a separate scheduled job (see
[Recovering stuck events](#recovering-stuck-events) below).

### Recovering stuck events

`SqlEventStore::recoverStuckEvents(CarbonInterval $olderThan): int` resets events that are stuck
in `processing` back to `pending` so the next worker can claim them again. An event is "stuck"
when its most recent `processing` audit row is older than `$olderThan`. The recovery writes a
`pending` audit row tagged `Recovered from stuck processing state` so dashboards can distinguish
organic vs. recovered transitions. The method returns the number of events it recovered and is
safe to run alongside the main worker.

```php
use Carbon\CarbonInterval;

// In a separate cron task — e.g. every minute:
$recovered = $store->recoverStuckEvents(CarbonInterval::minutes(30));
```

The threshold should be comfortably longer than the longest dispatch you expect under healthy
conditions. Too tight a threshold will pull events that are simply taking a while back into
`pending` and double-dispatch them; listeners are expected to be idempotent regardless, but
unnecessary work is unnecessary work.

> Re-dispatch can re-fire listeners that already succeeded on the previous run. Listeners must
> be idempotent for the recovery path to be safe.

### Recovering stuck redeliveries

The same shape applies to the redelivery table:
`SqlRedeliveryStore::recoverStuckRedeliveries(CarbonInterval $olderThan): int` resets rows
wedged in `dispatching` back to `pending_retry` so a worker can claim them again on the next
`processNextRedelivery()` tick. A row is "stuck" when its `updated_at` is older than `$olderThan`.

```php
$recovered = $redeliveryStore->recoverStuckRedeliveries(CarbonInterval::minutes(30));
```

Same threshold guidance and same idempotency requirement as `recoverStuckEvents`.

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

## Fail-fast in dev, durable in prod

In local development and CI, letting a failing listener throw immediately is exactly what you want — fast, noisy feedback. In production the calculus is different: a single listener failure should not prevent the remaining listeners from running, nor should an infrastructure blip (a DB hiccup, a connection drop) leave the worker dead.

The library separates those concerns into two composable layers:

- **`RedeliveringListenerDispatcher`** — a decorator on the dispatcher. Catches a listener throw and writes a fresh row to the `RedeliveryStore`, so the failure is durable but the rest of the event's listeners still run.
- **`SilentEventProcessor`** / **`SilentRedeliveryProcessor`** — decorators on the processor. Catch anything that escapes `process()` (infra-level failures, the redelivery store's own writes failing, etc.) and log via PSR-3.

In dev, you skip both decorators — anything that goes wrong propagates to the call site. In prod, you compose them.

```php
// Dev / CI — listener throws propagate out of process()
$processor = new SequentialEventProcessor($subscribers); // DefaultListenerDispatcher by default
$processor->process($store);
```

```php
// Production — listener throws become redelivery rows; infra throws get logged
use Vesper\Tool\Event\Infrastructure\Dispatch\DefaultListenerDispatcher;
use Vesper\Tool\Event\Infrastructure\Dispatch\RedeliveringListenerDispatcher;
use Vesper\Tool\Event\Infrastructure\SequentialEventProcessor;
use Vesper\Tool\Event\Infrastructure\SilentEventProcessor;

$dispatcher = new RedeliveringListenerDispatcher(
    new DefaultListenerDispatcher(),
    $redeliveryStore,
);

$processor = new SilentEventProcessor(
    new SequentialEventProcessor($subscribers, $dispatcher),
    $logger,
);
$processor->process($store);
```

The silent decorator logs each escape with the throwable attached so you can diagnose what aborted the batch:

```
Event processor aborted.
{ exception: RuntimeException: ... }
```

(`SilentRedeliveryProcessor` logs `Redelivery processor aborted.` in the same shape.) Per-listener failures are caught one layer down by `RedeliveringListenerDispatcher` and don't pass through the silent decorator at all.

### PSR-3 logger

The silent decorators accept any [PSR-3](https://www.php-fig.org/psr/psr-3/) `LoggerInterface`. vesper php-events ships no concrete logger — supply whichever one your application already uses:

```php
// Monolog
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('events');
$logger->pushHandler(new StreamHandler('php://stderr'));

$processor = new SilentEventProcessor(
    new SequentialEventProcessor($subscribers, $dispatcher),
    $logger,
);
```

```php
// Laravel (already PSR-3 compatible)
$processor = new SilentEventProcessor(
    new SequentialEventProcessor($subscribers, $dispatcher),
    app('log'),
);
```

### Choosing a wiring per environment

| Environment | Wiring | Behaviour |
|---|---|---|
| Local / CI | `SequentialEventProcessor` + `DefaultListenerDispatcher` | A listener throw propagates out of `process()`. Nothing is hidden. |
| Production | `SilentEventProcessor` wrapping `SequentialEventProcessor`, dispatcher = `RedeliveringListenerDispatcher(DefaultListenerDispatcher, $redeliveryStore)` | A listener throw is written to `event_outbox_redelivery` and the next listener runs. Anything else (DB blip, etc.) is logged and the batch aborts; the next scheduled tick picks it up. |

---

## Automatic retry & failure tracking

A failed listener should not become a silent loss. This library supports automatic retry and
durable failure tracking on a **per-(event, listener)** basis: if a single listener of an event
fails, only that listener is retried — the others continue to run and successful deliveries are
not re-fired.

The model is **fully async**: when a listener throws inside `SequentialEventProcessor`, the
`RedeliveringListenerDispatcher` catches the exception and persists it to the redelivery table
immediately (with `attempt_number = 1` and `next_retry_at = now`); the main outbox worker then
moves on to the next listener / next event. A separate scheduled job runs
`SequentialRedeliveryProcessor::process()`, which drains the redelivery table and consults the
`RetryPolicy` per failed attempt to decide whether to reschedule or mark the row permanently
failed.

The split keeps the event processor simple — it knows nothing about the redelivery store,
retry policies, attempt counts, or permanent failure. The dispatcher decorator owns
"failure → row"; the redelivery processor owns "row → retry decision".

### Retry policy

A `RetryPolicy` decides whether a failed dispatch should be retried, and at what time. It is
consulted only by `SequentialRedeliveryProcessor`, not by the event processor:

```php
use Vesper\Tool\Event\Retry\RetryPolicy;

interface RetryPolicy
{
    /** @return CarbonImmutable|null  null when no further retries should be made */
    public function nextRetryAt(int $previousAttempt): ?CarbonImmutable;
}
```

Two implementations ship out of the box:

- **`NoRetryPolicy`** *(default)* — never retries. The first redelivery attempt that fails is
  marked permanently failed.
- **`ExponentialBackoffRetryPolicy`** — five total attempts (one initial + four retries) with
  delays of `100ms, 500ms, 1min, 5min` by default. Every retry is persisted back to the
  redelivery table and picked up on a future `RedeliveryProcessor::process()` run.

```php
use Vesper\Tool\Event\Infrastructure\Retry\ExponentialBackoffRetryPolicy;

// Default delays — 100ms, 500ms, 1min, 5min.
$retryPolicy = new ExponentialBackoffRetryPolicy();

// Or roll your own delays:
$retryPolicy = new ExponentialBackoffRetryPolicy(delaysMs: [50, 250, 1_000, 30_000]);
```

Without a `RedeliveringListenerDispatcher` wrapping the dispatcher, any listener throw
propagates out of `process()` (fail-fast) — there is nowhere to persist the failed attempt.
Wrap the dispatcher to enable durable retry.

### Redelivery store

The `RedeliveryStore` interface owns per-listener retry state — when an attempt failed, how
many attempts have been made, when the next one should run, what the last error was. Two
implementations:

- **`InMemoryRedeliveryStore`** — array-backed, for tests and dev.
- **`SqlRedeliveryStore`** — durable, MySQL/SQLite-compatible. Auto-creates its
  `event_outbox_redelivery` table on first construction. Worker-safe via `FOR UPDATE SKIP LOCKED`
  on MySQL.

```php
use Vesper\Tool\Event\Infrastructure\Redelivery\SqlRedeliveryStore;

$store = new SqlRedeliveryStore($pdo); // schema created here if not present
```

### Wiring it together

```php
use Vesper\Tool\Event\Infrastructure\Dispatch\DefaultListenerDispatcher;
use Vesper\Tool\Event\Infrastructure\Dispatch\RedeliveringListenerDispatcher;
use Vesper\Tool\Event\Infrastructure\Redelivery\SequentialRedeliveryProcessor;
use Vesper\Tool\Event\Infrastructure\Redelivery\SilentRedeliveryProcessor;
use Vesper\Tool\Event\Infrastructure\Redelivery\SqlRedeliveryStore;
use Vesper\Tool\Event\Infrastructure\Retry\ExponentialBackoffRetryPolicy;
use Vesper\Tool\Event\Infrastructure\SequentialEventProcessor;
use Vesper\Tool\Event\Infrastructure\SilentEventProcessor;

$default = new DefaultListenerDispatcher(
    ignoredExceptions: [
        UserNotFoundException::class,
        InvalidPayloadException::class,
    ],
);

$redeliveryStore = new SqlRedeliveryStore($pdo);

// Event flow: failures route into $redeliveryStore via the dispatcher decorator;
// anything else that escapes the batch is logged by SilentEventProcessor.
$eventProcessor = new SilentEventProcessor(
    new SequentialEventProcessor(
        $subscribers,
        new RedeliveringListenerDispatcher($default, $redeliveryStore),
    ),
    $logger,
);

// Redelivery flow: plain dispatcher (no Redelivering wrap — that would double-schedule).
// The retry policy decides whether each failed attempt reschedules or marks failed.
$redeliveryProcessor = new SilentRedeliveryProcessor(
    new SequentialRedeliveryProcessor(
        $subscribers,
        $default,
        new ExponentialBackoffRetryPolicy(),
    ),
    $logger,
);
```

Run the two processors as separate scheduled jobs:

```php
// In one cron task / queue worker:
$eventProcessor->process($eventStore);

// In another cron task / queue worker:
$redeliveryProcessor->process($redeliveryStore);
```

Long backoffs (e.g. the default 1min / 5min steps) won't be picked up until a future
`RedeliveryProcessor::process()` call after their `next_retry_at` passes — which is exactly how
outbox workers already poll on a schedule.

### Ignored exceptions (skip-list)

Some listener failures are not bugs — they're expected domain outcomes that the application
already handles upstream (e.g. `UserNotFoundException`, `OrderAlreadyShipped`). Retrying them
wastes time and reporting them spams the error tracker.

The `ignoredExceptions` constructor parameter on `DefaultListenerDispatcher` takes a list of
`Throwable` class-strings. Matching is `instanceof`-based, so subclasses are also matched. When
a listener throws an ignored exception the dispatcher swallows it and returns normally —
indistinguishable to callers from a clean run:

- **No retry attempt** — `RedeliveringListenerDispatcher` only catches throws that propagate
  past `DefaultListenerDispatcher`, so ignored exceptions never reach the redelivery store.
- **No PSR-3 log line** — nothing escaped the processor for `SilentEventProcessor` to log.
- **No row written to `event_outbox_redelivery`** by the event flow.
- **No exception propagation** — the next listener for the same event runs as normal.
- **Marked succeeded** if encountered during redelivery — the listener has "handled" the event.

The recommended pattern is to share the same list with whatever already configures your
application's error reporter (Sentry/Bugsnag/etc.) so behaviour stays consistent across the
boundary: anything your app considers "expected and not worth a page" is also considered
expected here.

### Permanently failed dispatches

When `SequentialRedeliveryProcessor` runs a due retry and the `RetryPolicy` returns `null` (no
more retries), the row is marked `status = 'failed'` in `event_outbox_redelivery`. The row
stays in the table so operators can inspect it. The redelivery processor does **not** throw —
the loop continues to the next due row.

The event processor itself never marks anything `failed`. The only path to a `failed` row is
through the redelivery processor's retry-policy exhaustion. If you want fail-fast semantics in
local/CI, simply omit the `RedeliveringListenerDispatcher` wrapper — listener throws will then
propagate out of `process()` on the first attempt instead.

### Re-triggering a failed dispatch

`RedeliveryStore::retryNow($eventId, $listener)` re-queues a dispatch for immediate retry,
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
RawEventStatus::processed   // event was dispatched to every listener (per-listener outcomes live in event_outbox_redelivery)
```

---

## Operational queries

Useful queries against the existing tables:

- Listeners with permanent failures — `SELECT * FROM event_outbox_redelivery WHERE status = 'failed'`.
- Events recovered by the stuck-events sweeper — `SELECT * FROM event_outbox_status WHERE error_message = 'Recovered from stuck processing state'`.
- Dispatch latency — average time between consecutive rows in `event_outbox_status` for the same `event_id`.

`recoverStuckEvents()` re-claims wedged rows (`processing → pending`) for re-dispatch. The other
plausible recovery mode — **force-complete** (`processing → processed`) — is intentionally not
exposed: it's only safe when the dispatch is believed to have finished but the bookkeeping
commit was lost, and that's a judgement call that belongs in operator tooling rather than a
library API. If you need it, the operation is two statements: `UPDATE event_outbox SET status =
'processed' WHERE id = ?` and an audit insert with a recovery marker.

See [ROADMAP.md](ROADMAP.md) for known limitations and planned work.

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
