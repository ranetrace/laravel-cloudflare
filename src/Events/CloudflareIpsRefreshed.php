<?php

namespace Ranetrace\LaravelCloudflare\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CloudflareIpsRefreshed
{
    use Dispatchable;

    /**
     * @param  array<int, string>  $ipv4
     * @param  array<int, string>  $ipv6
     */
    public function __construct(
        public array $ipv4,
        public array $ipv6,
    ) {}
}
