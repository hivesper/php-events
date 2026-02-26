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
        public string $type,
        public RayEventStatus $status,
        public array $payload,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $publishAt,
    ) {
    }

    public static function create(
        string $type,
        array $payload,
        ?CarbonImmutable $publishAt = null,
    ): self {
        return new self(
            id: Uuid::uuid7(),
            type: $type,
            status: RayEventStatus::pending,
            payload: $payload,
            createdAt: CarbonImmutable::now(),
            publishAt: $publishAt ?? CarbonImmutable::now(),
        );
    }

    public static function retrieve(
        string $id,
        string $type,
        RayEventStatus $status,
        array $payload,
        CarbonImmutable $createdAt,
        CarbonImmutable $publishAt,
    ): self {
        return new self(
            id: $id,
            type: $type,
            status: $status,
            payload: $payload,
            createdAt: $createdAt,
            publishAt: $publishAt,
        );
    }
}
