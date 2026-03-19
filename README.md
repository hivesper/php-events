# ☀️ php-ray

> *Hit the road jack, and don't you come back… unless it's via Ray*

A lightweight, framework-agnostic event system for PHP 8.4+ built around the **transactional outbox pattern**.

Events are first written to a durable store (in-memory or SQL), then dispatched to subscribers by a processor. This decouples publishing from handling and makes event delivery reliable across process boundaries.

---

## Features

- **Publish events** with a typed payload, a string `type`, and an optional `publishAt` timestamp
- **Subscribe** to event types with any callable
- **Process** queued events sequentially — each event is routed to every registered subscriber by type
- **Two stores out of the box** — in-memory for tests/dev, SQL (MySQL / SQLite) for production
- **Outbox pattern** — events are marked `processed` only after they're successfully dequeued
- **Scheduled delivery** — set `publishAt` in the future; the processor only picks up events whose time has come
- **Worker-safe** — MySQL store uses `FOR UPDATE SKIP LOCKED` to allow multiple workers without double-processing
- **Auto schema** — `SqlEventStore` creates its own tables on first boot, no migrations needed

---

## Installation

```bash
composer require tcds-io/php-ray
```

> Requires PHP ≥ 8.4, `ext-json`, `ext-pdo`.

---

## Core concepts

```
┌──────────────────┐   add()   ┌───────────────┐   next()  ┌───────────────────────┐
│  EventPublisher  │──────────▶│   EventStore  │──────────▶│  EventProcessor       │
└──────────────────┘           └───────────────┘           │  (reads + dispatches) │
                                                           └───────────┬───────────┘
                                                                       │ of(type)
                                                           ┌───────────▼───────────┐
                                                           │    EventSubscriber    │
                                                           │   (holds callables)  │
                                                           └───────────────────────┘
```

| Class | Role |
|---|---|
| `RayEvent` | Immutable value object representing a single event |
| `EventStore` | Interface — a durable FIFO queue of `RayEvent` |
| `EventPublisher` | Pushes a `RayEvent` into the store, returns its ID |
| `EventSubscriber` | Registry of `type → callable[]` mappings |
| `EventProcessor` | Interface — drains the store and dispatches to subscribers |
| `EventSubscriberBuilder` | Fluent builder that produces a ready-to-use `EventSubscriberMap` |

---

## Quick start

```php
use Carbon\CarbonImmutable;
use Tcds\Io\Ray\EventPublisher;
use Tcds\Io\Ray\EventSubscriberMap;
use Tcds\Io\Ray\Infrastructure\InMemoryEventStore;
use Tcds\Io\Ray\Infrastructure\SequentialEventProcessor;
use Tcds\Io\Ray\RayEvent;

// 1. Wire up the store, publisher, and processor
$store     = new InMemoryEventStore();
$publisher = new EventPublisher($store);

$subscribers = new EventSubscriberMap();
$processor   = new SequentialEventProcessor($subscribers);

// 2. Register subscribers
$subscribers->subscribe('order.placed', function (RayEvent $event): void {
    echo "Order placed: " . $event->payload['order_id'] . PHP_EOL;
});

$subscribers->subscribe('order.placed', function (RayEvent $event): void {
    echo "Sending confirmation email..." . PHP_EOL;
});

// 3. Publish an event
$publisher->publish(
    RayEvent::create(
        type: 'order.placed',
        payload: ['order_id' => 42, 'total' => 99.99],
        publishAt: CarbonImmutable::now(),
    )
);

// 4. Process — both subscribers fire in registration order
$processor->process($store);

// Output:
// Order placed: 42
// Sending confirmation email...
```

---

## RayEvent

Events are created via two static factories:

```php
// Create a brand-new event (generates a UUID v7 id, sets status → pending)
$event = RayEvent::create(
    type: 'payment.received',
    payload: ['amount' => 150, 'currency' => 'USD'],
    publishAt: CarbonImmutable::now(),
);

echo $event->id;        // uuid7 string
echo $event->type;      // "payment.received"
echo $event->status;    // RayEventStatus::pending
print_r($event->payload); // ['amount' => 150, 'currency' => 'USD']
```

```php
// Reconstruct an event from persisted data (used internally by SqlEventStore)
$event = RayEvent::retrieve(
    id: $row['id'],
    type: $row['type'],
    status: RayEventStatus::from($row['status']),
    payload: json_decode($row['payload'], true),
    createdAt: new CarbonImmutable($row['created_at']),
    publishAt: new CarbonImmutable($row['publish_at']),
);
```

### Scheduled events

Pass any `CarbonImmutable` timestamp as `publishAt` — the SQL store only dequeues events whose `publish_at <= now()`:

```php
$publisher->publish(
    RayEvent::create(
        type: 'subscription.reminder',
        payload: ['user_id' => 7],
        publishAt: CarbonImmutable::now()->addDays(3),
    )
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
use Tcds\Io\Ray\Infrastructure\SqlEventStore;

$pdo   = new PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass');
$store = new SqlEventStore($pdo); // schema created here if not present
```

**Schema created automatically:**

```sql
CREATE TABLE event_outbox (
    id         VARCHAR(32)  NOT NULL PRIMARY KEY,
    type       VARCHAR(255) NOT NULL,
    status     VARCHAR(255) NOT NULL,  -- 'pending' | 'processed' | 'failed'
    payload    JSON         NOT NULL,
    created_at DATETIME     NOT NULL,
    publish_at DATETIME     NOT NULL,
    INDEX idx_event_outbox_status_publish (status, publish_at)
);
```

> MySQL workers use `SELECT … FOR UPDATE SKIP LOCKED` for safe concurrent processing.

---

## EventSubscriber

Subscribe any callable to a named event type:

```php
$subscribers = new EventSubscriber();

// Closure
$subscribers->subscribe('order.cancelled', function (RayEvent $event): void {
    // ...
});

// First-class callable syntax
$subscribers->subscribe('order.shipped', $myService->onOrderShipped(...));

// Pre-populate via constructor (useful for DI containers)
$subscribers = new EventSubscriber([
    'order.placed' => [$listenerA, $listenerB],
    'payment.failed' => [$alertHandler],
]);
```

Multiple subscribers for the same type are called **in registration order**.

---

## EventSubscriberBuilder

A fluent builder that produces a ready-to-use `EventSubscriber`. Useful for wiring up callables in one place before handing the result to `SequentialEventProcessor`:

```php
use Tcds\Io\Ray\EventSubscriberBuilder;

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
use Tcds\Io\Ray\EventProcessor;
use Tcds\Io\Ray\EventStore;

class MyProcessor implements EventProcessor
{
    public function process(EventStore $store): void
    {
        while ($event = $store->next()) {
            // your dispatch logic
        }
    }
}
```

---

## RayEventStatus

```php
RayEventStatus::pending    // event is waiting to be processed
RayEventStatus::processed  // event was successfully dequeued
RayEventStatus::failed     // reserved for failed-delivery tracking
```

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
