<?php

namespace Ranetrace\LaravelCloudflare\Commands;

use Illuminate\Console\Command;
use Ranetrace\LaravelCloudflare\LaravelCloudflare;

class CloudflareRefreshCommand extends Command
{
    public $signature = 'cloudflare:refresh';

    public $description = 'Fetch and cache the latest Cloudflare IPv4/IPv6 ranges.';

    public function handle(): int
    {
        $service = app(LaravelCloudflare::class);

        $this->info('Refreshing Cloudflare IP ranges...');
        $success = $service->refresh();

        if ($success) {
            $this->info('Cloudflare IP ranges refreshed successfully.');
        } else {
            $this->error('Failed to refresh Cloudflare IP ranges. Using cached or fallback data.');
        }

        $v4 = $service->ipv4();
        $v6 = $service->ipv6();
        $all = $service->all();

        $this->line('IPv4 (current or fallback): '.count($v4));
        $this->line('IPv6 (current or fallback): '.count($v6));
        $this->line('All (current or fallback): '.count($all));

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
