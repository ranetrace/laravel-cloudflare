<?php

namespace Ranetrace\LaravelCloudflare\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Ranetrace\LaravelCloudflare\LaravelCloudflare;

class CloudflareClearCommand extends Command
{
    public $signature = 'cloudflare:clear {--current : Clear only current cache} {--last-good : Clear only last_good durable store}';

    public $description = 'Clear cached Cloudflare IP ranges (current cache and/or the durable last_good store)';

    public function handle(): int
    {
        $service = app(LaravelCloudflare::class);
        $keys = Config::get('laravel-cloudflare.cache.keys');

        $onlyCurrent = (bool) $this->option('current');
        $onlyLastGood = (bool) $this->option('last-good');

        // With no flags, clear both; otherwise clear only the requested store(s).
        $clearCurrent = $onlyCurrent || ! $onlyLastGood;
        $clearLastGood = $onlyLastGood || ! $onlyCurrent;

        $clearedCount = 0;

        if ($clearCurrent) {
            $currentKeys = [
                Arr::get($keys, 'current.all', 'cloudflare:ips:current'),
                Arr::get($keys, 'current.v4', 'cloudflare:ips:v4:current'),
                Arr::get($keys, 'current.v6', 'cloudflare:ips:v6:current'),
            ];

            foreach ($currentKeys as $key) {
                $service->cache->forget($key);
                $clearedCount++;
            }

            $this->info('Cleared current cache keys.');
        }

        if ($clearLastGood) {
            $service->durable->forgetLists();
            $clearedCount++;

            $this->info('Cleared durable last_good store ('.$service->durable->location().').');
        }

        $this->line('Total stores cleared: '.$clearedCount);

        return self::SUCCESS;
    }
}
