<?php

namespace Vesper\Tool\Event;

enum RawEventStatus: string
{
    case pending = 'pending';
    case processed = 'processed';
    case failed = 'failed';
}
