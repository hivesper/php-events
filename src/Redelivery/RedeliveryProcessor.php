<?php

namespace Vesper\Tool\Event\Redelivery;

interface RedeliveryProcessor
{
    public function process(RedeliveryStore $store): void;
}
