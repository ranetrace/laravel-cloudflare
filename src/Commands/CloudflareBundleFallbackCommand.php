<?php

namespace Ranetrace\LaravelCloudflare\Commands;

use Illuminate\Console\Command;
use Ranetrace\LaravelCloudflare\LaravelCloudflare;

class CloudflareBundleFallbackCommand extends Command
{
    public $signature = 'cloudflare:bundle-fallback {--path= : Override the output path for the bundled fallback file}';

    public $description = 'MAINTAINER TOOL: fetch the live Cloudflare ranges and rewrite resources/cloudflare-ips.php. Run from the package repo before tagging a release; not intended for host apps.';

    public function handle(LaravelCloudflare $service): int
    {
        $this->info('Fetching live Cloudflare IP ranges...');

        $ipv4 = $service->fetchLive('ipv4');
        $ipv6 = $service->fetchLive('ipv6');

        if ($ipv4 === [] || $ipv6 === []) {
            $this->error('Refusing to write an empty bundled fallback (ipv4: '.count($ipv4).', ipv6: '.count($ipv6).').');

            return self::FAILURE;
        }

        $path = $this->option('path') ?: dirname(__DIR__, 2).'/resources/cloudflare-ips.php';

        $directory = dirname($path);
        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        if (file_put_contents($path, $this->render($ipv4, $ipv6)) === false) {
            $this->error("Failed to write bundled fallback to {$path}.");

            return self::FAILURE;
        }

        $this->info('Wrote '.count($ipv4).' IPv4 and '.count($ipv6)." IPv6 ranges to {$path}.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $ipv4
     * @param  array<int, string>  $ipv6
     */
    protected function render(array $ipv4, array $ipv6): string
    {
        $date = now()->toDateString();

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = '/*';
        $lines[] = ' * Package-bundled Cloudflare IP ranges.';
        $lines[] = ' *';
        $lines[] = ' * This is the authoritative cold-start fallback, used only on a brand-new';
        $lines[] = ' * install before the first `cloudflare:refresh` has run (and only when the';
        $lines[] = ' * published config `fallback.ipv4/ipv6` is empty).';
        $lines[] = ' *';
        $lines[] = ' * DO NOT edit by hand. Regenerate from the live Cloudflare endpoints with:';
        $lines[] = ' *';
        $lines[] = ' *     php artisan cloudflare:bundle-fallback';
        $lines[] = ' *';
        $lines[] = ' * (a maintainer/release tool, run from the package repo before tagging).';
        $lines[] = ' *';
        $lines[] = " * Last generated: {$date}.";
        $lines[] = ' */';
        $lines[] = '';
        $lines[] = 'return [';
        $lines[] = $this->renderList('ipv4', $ipv4);
        $lines[] = $this->renderList('ipv6', $ipv6);
        $lines[] = '];';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $entries
     */
    protected function renderList(string $key, array $entries): string
    {
        $lines = ["    '{$key}' => ["];
        foreach ($entries as $entry) {
            $lines[] = "        '{$entry}',";
        }
        $lines[] = '    ],';

        return implode("\n", $lines);
    }
}
