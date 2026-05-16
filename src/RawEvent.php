<?php

namespace Vesper\Tool\Event;

use Carbon\CarbonImmutable;
use DomainException;
use Ramsey\Uuid\Uuid;

class RawEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        private(set) RawEventStatus $status,
        public readonly array $payload,
        public readonly CarbonImmutable $createdAt,
        public readonly CarbonImmutable $publishAt,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function create(
        string $name,
        array $payload,
        ?CarbonImmutable $publishAt = null,
    ): self {
        return new self(
            id: Uuid::uuid7(),
            name: $name,
            status: RawEventStatus::pending,
            payload: $payload,
            createdAt: CarbonImmutable::now(),
            publishAt: $publishAt ?? CarbonImmutable::now(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function retrieve(
        string $id,
        string $name,
        RawEventStatus $status,
        array $payload,
        CarbonImmutable $createdAt,
        CarbonImmutable $publishAt,
    ): self {
        return new self(
            id: $id,
            name: $name,
            status: $status,
            payload: $payload,
            createdAt: $createdAt,
            publishAt: $publishAt,
        );
    }

    public function claim(): self
    {
        $this->requireStatus(RawEventStatus::pending, 'claim');

        $this->status = RawEventStatus::processing;

        return $this;
    }

    public function markProcessed(): self
    {
        $this->requireStatus(RawEventStatus::processing, 'markProcessed');

        $this->status = RawEventStatus::processed;

        return $this;
    }

    private function requireStatus(RawEventStatus $expected, string $operation): void
    {
        if ($this->status !== $expected) {
            throw new DomainException(
                "Cannot $operation an event in status {$this->status->value} (expected $expected->value).",
            );
        }
    }
}
