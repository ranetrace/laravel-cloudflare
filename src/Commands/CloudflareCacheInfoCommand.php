<?php

namespace Ranetrace\LaravelCloudflare\Commands;

use Illuminate\Console\Command;
use Ranetrace\LaravelCloudflare\LaravelCloudflare;

class CloudflareCacheInfoCommand extends Command
{
    public $signature = 'cloudflare:cache-info {--json : Output as JSON}';

    public $description = 'Display information about the cached Cloudflare IP ranges.';

    public function handle(): int
    {
        $service = app(LaravelCloudflare::class);

        $info = $service->cacheInfo();

        if ($this->option('json')) {
            $this->line(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Cloudflare IP Cache Information');
        $this->line('Cache Store      : '.($info['store'] ?? 'default'));
        $ttl = $info['configured_ttl'];
        $this->line('Configured TTL   : '.($ttl === null ? 'forever' : ($ttl.'s')));

        $this->newLine();
        $this->line('Segments:');

        $this->line('  current (cache):');
        foreach (['v4', 'v6', 'all'] as $label) {
            $segmentInfo = $info['segments']['current'][$label] ?? null;
            if ($segmentInfo === null) {
                continue;
            }
            $status = $segmentInfo['present'] ? 'cached' : 'missing';
            $line = '    '
                .str_pad($label, 3, ' ', STR_PAD_RIGHT)
                .' key '
                .str_pad($segmentInfo['key'], 30, ' ', STR_PAD_RIGHT)
                .' status: '
                .str_pad($status, 7, ' ', STR_PAD_RIGHT)
                .' count: '
                .$segmentInfo['count'];
            $this->line($line);
        }
        $this->newLine();

        $lastGood = $info['last_good'] ?? [];
        $this->line('  last_good (durable '.($lastGood['driver'] ?? 'file').'):');
        $this->line('    location: '.($lastGood['location'] ?? 'n/a'));
        foreach (['v4', 'v6', 'all'] as $label) {
            $segmentInfo = $lastGood[$label] ?? null;
            if ($segmentInfo === null) {
                continue;
            }
            $status = $segmentInfo['present'] ? 'cached' : 'missing';
            $line = '    '
                .str_pad($label, 3, ' ', STR_PAD_RIGHT)
                .' status: '
                .str_pad($status, 7, ' ', STR_PAD_RIGHT)
                .' count: '
                .$segmentInfo['count'];
            $this->line($line);
        }
        $this->newLine();

        if (isset($info['fallback'])) {
            $v4Count = $info['fallback']['ipv4_count'] ?? 0;
            $v6Count = $info['fallback']['ipv6_count'] ?? 0;
            if ($v4Count > 0 || $v6Count > 0) {
                $this->line("Static fallback: {$v4Count} IPv4, {$v6Count} IPv6 (config or package-bundled)");
                $this->newLine();
            }
        }

        $this->comment('Use cloudflare:refresh to populate or refresh the current cache (last_good updates on successful refresh).');

        return self::SUCCESS;
    }
}
