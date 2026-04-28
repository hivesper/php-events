<?php

namespace Vesper\Tool\Event\Infrastructure\Retry;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Override;
use Vesper\Tool\Event\Retry\RetryPolicy;

final readonly class ExponentialBackoffRetryPolicy implements RetryPolicy
{
    /** @var list<int> */
    private array $delaysMs;

    /**
     * @param list<int> $delaysMs delay before each retry in milliseconds; the i-th element is the
     *                            delay before attempt (i + 2). Default: 100ms, 500ms, 1min, 5min,
     *                            yielding 5 total attempts (one initial + four retries).
     */
    public function __construct(
        array $delaysMs = [100, 500, 60_000, 300_000],
    ) {
        foreach ($delaysMs as $ms) {
            if ($ms < 0) {
                throw new InvalidArgumentException("Retry delays must be non-negative, got {$ms}.");
            }
        }

        $this->delaysMs = $delaysMs;
    }

    #[Override]
    public function nextRetryAt(int $previousAttempt): ?CarbonImmutable
    {
        $index = $previousAttempt - 1;

        if ($index < 0 || $index >= count($this->delaysMs)) {
            return null;
        }

        return CarbonImmutable::now()->addMilliseconds($this->delaysMs[$index]);
    }
}
