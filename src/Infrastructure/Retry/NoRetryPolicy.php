<?php

namespace Vesper\Tool\Event\Infrastructure\Retry;

use Carbon\CarbonImmutable;
use Override;
use Vesper\Tool\Event\Retry\RetryPolicy;

final readonly class NoRetryPolicy implements RetryPolicy
{
    #[Override]
    public function nextRetryAt(int $previousAttempt): ?CarbonImmutable
    {
        return null;
    }
}
