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
- **Two processors out of the box** — standard (fail-fast for local/CI) and silent (log-and-continue for production)
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
| `SequentialEventProcessor` | Built-in processor — dispatches events one by one; exceptions propagate (fail-fast) |
| `SilentSequentialEventProcessor` | Built-in processor — same as above but catches per-listener failures and logs them via PSR-3 |
| `EventSubscriberBuilder` | Fluent builder that produces a ready-to-use `EventSubscriberMap` |

---

## Quick start

```php
use Tcds\Io\Ray\EventPublisher;
use Tcds\Io\Ray\EventSubscriberMap;
use Tcds\Io\Ray\Infrastructure\InMemoryEventStore;
use Tcds\Io\Ray\Infrastructure\JacksonSerializer;
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

### JacksonSerializer *(built-in)*

Derives the event name from the short class name (PascalCase) and uses Jackson's `ArrayObjectMapper` to serialize the object to an array payload. Handles constructor-promoted properties, nested objects, and collections automatically:

```php
use Tcds\Io\Ray\Infrastructure\JacksonSerializer;

$publisher = new EventPublisher($store, new JacksonSerializer());

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
use Tcds\Io\Ray\EventHydrator;

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

## Silent event processing

In local development and CI, letting a failing listener throw immediately is exactly what you want — fast, noisy feedback. In production the calculus is different: a single listener failure should not prevent the remaining listeners for that event from running, nor should it block every subsequent event in the queue. `SilentSequentialEventProcessor` wraps each listener dispatch in a try-catch, logs the failure, and carries on.

```php
use Tcds\Io\Ray\Infrastructure\SilentSequentialEventProcessor;

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

`SilentSequentialEventProcessor` accepts any [PSR-3](https://www.php-fig.org/psr/psr-3/) `LoggerInterface`. php-ray ships no concrete logger — supply whichever one your application already uses:

```php
// Monolog
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('ray');
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

> **Coming soon:** Automatic retry and failure tracking will be added to the base processor. `SilentSequentialEventProcessor` is designed to remain the right choice for production once that lands — it already captures everything needed to identify and reprocess a specific failed listener.

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
