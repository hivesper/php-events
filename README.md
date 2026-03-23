# ☀️ php-ray

> *Hit the road jack, and don't you come back… unless it's via Ray*

A lightweight, framework-agnostic event system for PHP 8.4+ built around the **transactional outbox pattern**.

Events are first written to a durable store (in-memory or SQL), then dispatched to subscribers by a processor. This decouples publishing from handling and makes event delivery reliable across process boundaries.

---

## Features

- **Publish events** with a typed payload, a string `name`, and an optional `publishAt` timestamp
- **Subscribe** to event types with any callable
- **Process** queued events sequentially — each event is routed to every registered subscriber by type
- **Two stores out of the box** — in-memory for tests/dev, SQL (MySQL / SQLite) for production
- **Outbox pattern** — events are marked `processed` only after they're successfully dequeued
- **Scheduled delivery** — set `publishAt` in the future; the processor only picks up events whose time has come
- **Worker-safe** — MySQL store uses `FOR UPDATE SKIP LOCKED` to allow multiple workers without double-processing
- **Auto schema** — `SqlEventStore` creates its own tables on first boot, no migrations needed
- **Clean architecture boundaries** — `EventSerializer` and `EventHydrator` keep `RayEvent` out of your application layer

---

## Installation

```bash
composer require tcds-io/php-ray
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
| `RayEvent` | Immutable value object representing a single stored event |
| `EventStore` | Interface — a durable FIFO queue of `RayEvent` |
| `EventSerializer` | Interface — converts a domain event object into a `SerializedEvent` |
| `EventHydrator` | Interface — reconstructs a domain event object from a stored name + payload |
| `SerializedEvent` | Value object holding the event `name` and `payload` array |
| `EventPublisher` | Serializes a domain event and pushes it into the store, returning its ID |
| `EventSubscriberMap` | Registry of `name → callable[]` mappings |
| `EventProcessor` | Interface — drains the store and dispatches to subscribers |
| `EventSubscriberBuilder` | Fluent builder that produces a ready-to-use `EventSubscriberMap` |

---

## Quick start

```php
use Carbon\CarbonImmutable;
use Tcds\Io\Ray\EventPublisher;
use Tcds\Io\Ray\EventSubscriberMap;
use Tcds\Io\Ray\Infrastructure\InMemoryEventStore;
use Tcds\Io\Ray\Infrastructure\PublicPropertiesSerializer;
use Tcds\Io\Ray\Infrastructure\RawPayloadHydrator;
use Tcds\Io\Ray\Infrastructure\SequentialEventProcessor;

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
$publisher  = new EventPublisher($store, new PublicPropertiesSerializer());

$subscribers = new EventSubscriberMap();
$processor   = new SequentialEventProcessor($subscribers, hydrator: new RawPayloadHydrator());

// 3. Register subscribers
$subscribers->subscribe('OrderPlaced', function (object $event): void {
    echo "Order placed: " . $event->orderId . PHP_EOL;
});

$subscribers->subscribe('OrderPlaced', function (object $event): void {
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

## RayEvent

`RayEvent` is an internal value object used by the store layer. Application code does not construct or receive `RayEvent` directly — use `EventSerializer` when publishing and `EventHydrator` when processing (see below).

Events are created via two static factories:

```php
// Create a brand-new event (generates a UUID v7 id, sets status → pending)
$event = RayEvent::create(
    name: 'payment.received',
    payload: ['amount' => 150, 'currency' => 'USD'],
    publishAt: CarbonImmutable::now(),
);

echo $event->id;        // uuid7 string
echo $event->name;      // "payment.received"
echo $event->status;    // RayEventStatus::pending
print_r($event->payload); // ['amount' => 150, 'currency' => 'USD']
```

```php
// Reconstruct an event from persisted data (used internally by SqlEventStore)
$event = RayEvent::retrieve(
    id: $row['id'],
    name: $row['name'],
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
    new SubscriptionReminder(userId: 7),
    publishAt: CarbonImmutable::now()->addDays(3),
);
```

---

## EventSerializer

`EventSerializer` converts a domain event object into a `SerializedEvent` (a `name` string and a `payload` array) before it is stored. `EventPublisher` calls it automatically — application code never touches `RayEvent` directly.

```php
interface EventSerializer
{
    public function serialize(object $event): SerializedEvent;
}
```

### PublicPropertiesSerializer *(built-in)*

Derives the event name from the short class name (PascalCase) and serializes all public properties as the payload:

```php
use Tcds\Io\Ray\Infrastructure\PublicPropertiesSerializer;

$publisher = new EventPublisher($store, new PublicPropertiesSerializer());

// OrderPlaced { orderId: 42, total: 99.99 }
// → SerializedEvent { name: 'OrderPlaced', payload: ['orderId' => 42, 'total' => 99.99] }
```

> **Warning:** Renaming the class silently changes the event name, breaking any consumers subscribed to the old name. Use a custom `EventSerializer` with explicit, stable names when this matters across deployments.

### Custom serializer

Implement `EventSerializer` for explicit name mapping or complex payload graphs:

```php
use Tcds\Io\Ray\EventSerializer;
use Tcds\Io\Ray\SerializedEvent;

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

`EventHydrator` reconstructs a domain event object from the stored `name` and `payload` before it is dispatched to subscribers. `SequentialEventProcessor` calls it automatically.

```php
interface EventHydrator
{
    /** @param array<string, mixed> $payload */
    public function hydrate(string $name, array $payload): object;
}
```

### RawPayloadHydrator *(built-in)*

Casts the raw payload array to a generic `stdClass` object. Useful for simple use-cases and tests:

```php
use Tcds\Io\Ray\Infrastructure\RawPayloadHydrator;

$processor = new SequentialEventProcessor($subscribers, hydrator: new RawPayloadHydrator());

// Subscriber receives (object) ['orderId' => 42, 'total' => 99.99]
```

### Custom hydrator

Implement `EventHydrator` to return fully typed domain event objects to your subscribers:

```php
use Tcds\Io\Ray\EventHydrator;

final class AppEventHydrator implements EventHydrator
{
    public function hydrate(string $name, array $payload): object
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
use Tcds\Io\Ray\Infrastructure\SqlEventStore;

$pdo   = new PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass');
$store = new SqlEventStore($pdo); // schema created here if not present
```

**Schema created automatically:**

```sql
CREATE TABLE event_outbox (
    id         VARCHAR(36)  NOT NULL PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    status     VARCHAR(255) NOT NULL,  -- 'pending' | 'processed' | 'failed'
    payload    JSON         NOT NULL,
    created_at DATETIME     NOT NULL,
    publish_at DATETIME     NOT NULL,
    INDEX idx_event_outbox_status_publish (status, publish_at)
);
```

> MySQL workers use `SELECT … FOR UPDATE SKIP LOCKED` for safe concurrent processing.

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
