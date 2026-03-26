<?php

namespace Ranetrace\LaravelCloudflare\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Ranetrace\LaravelCloudflare\LaravelCloudflare;

class CloudflareClearCommand extends Command
{
    public $signature = 'cloudflare:clear {--current : Clear only current cache} {--last-good : Clear only last_good cache}';

    public $description = 'Clear cached Cloudflare IP ranges';

    public function handle(): int
    {
        $service = app(LaravelCloudflare::class);
        $keys = Config::get('laravel-cloudflare.cache.keys');

        $clearCurrent = $this->option('current') || (! $this->option('current') && ! $this->option('last-good'));
        $clearLastGood = $this->option('last-good') || (! $this->option('current') && ! $this->option('last-good'));

        $clearedKeys = [];

        if ($clearCurrent) {
            $currentKeys = [
                Arr::get($keys, 'current.all', 'cloudflare:ips:current'),
                Arr::get($keys, 'current.v4', 'cloudflare:ips:v4:current'),
                Arr::get($keys, 'current.v6', 'cloudflare:ips:v6:current'),
            ];

            foreach ($currentKeys as $key) {
                $service->cache->forget($key);
                $clearedKeys[] = $key;
            }

            $this->info('Cleared current cache keys.');
        }

        if ($clearLastGood) {
            $lastGoodKeys = [
                Arr::get($keys, 'last_good.all', 'cloudflare:ips:last_good'),
                Arr::get($keys, 'last_good.v4', 'cloudflare:ips:v4:last_good'),
                Arr::get($keys, 'last_good.v6', 'cloudflare:ips:v6:last_good'),
            ];

            foreach ($lastGoodKeys as $key) {
                $service->cache->forget($key);
                $clearedKeys[] = $key;
            }

            $this->info('Cleared last_good cache keys.');
        }

        $this->line('Total keys cleared: '.count($clearedKeys));

        return self::SUCCESS;
    }
}
