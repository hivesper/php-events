<?php

namespace Tcds\Io\Ray;

use Carbon\CarbonImmutable;
use Ramsey\Uuid\Uuid;

readonly class RayEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        public string $id,
        public string $name,
        public RayEventStatus $status,
        public array $payload,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $publishAt,
    ) {
    }

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
            status: RayEventStatus::pending,
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
        RayEventStatus $status,
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
}
