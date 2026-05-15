<?php

namespace Vesper\Tool\Event;

enum RedeliveryStatus: string
{
    case PendingRetry = 'pending_retry';
    case Failed = 'failed';
    case Succeeded = 'succeeded';
}
