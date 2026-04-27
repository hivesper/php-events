<?php

namespace Vesper\Tool\Event;

enum RawEventStatus: string
{
    case pending = 'pending';
    case processing = 'processing';
    case processed = 'processed';
    case failed = 'failed';
}
