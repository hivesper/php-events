<?php

namespace Vesper\Tool\Event\Redelivery;

enum RedeliveryStatus: string
{
    case PendingRetry = 'pending_retry';
    case Dispatching = 'dispatching';
    case Failed = 'failed';
    case Succeeded = 'succeeded';
}
