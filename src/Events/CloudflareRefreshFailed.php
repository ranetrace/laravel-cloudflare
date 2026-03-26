<?php

namespace Ranetrace\LaravelCloudflare\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CloudflareRefreshFailed
{
    use Dispatchable;

    public function __construct(
        public bool $ipv4Empty,
        public bool $ipv6Empty,
    ) {}
}
