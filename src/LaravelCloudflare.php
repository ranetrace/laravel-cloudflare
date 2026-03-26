<?php

namespace Ranetrace\LaravelCloudflare;

use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\SimpleCache\InvalidArgumentException;
use Ranetrace\LaravelCloudflare\Events\CloudflareIpsRefreshed;
use Ranetrace\LaravelCloudflare\Events\CloudflareRefreshFailed;
use Ranetrace\LaravelCloudflare\Exceptions\EmptyCacheException;
use Throwable;

class LaravelCloudflare
{
    /**
     * Runtime cache to prevent multiple cache lookups in a single request.
     *
     * @var array<string, array<int, string>>
     */
    protected array $memoized = [];

    public function __construct(
        public ?CacheContract $cache = null,
    ) {
        // Use configured cache store or default
        $store = Config::get('laravel-cloudflare.cache.store');
        if ($cache === null) {
            $this->cache = $store ? Cache::store($store) : Cache::store();
        }
    }

    /**
     * Get all Cloudflare IP ranges (both IPv4 and IPv6) from cache only.
     * Order: current -> last_good -> config fallback -> [] (logs warning if empty and allowed).
     *
     * @return array<int, string>
     *
     * @throws EmptyCacheException if cache is empty and throw_on_empty is enabled
     */
    public function all(): array
    {
        if (isset($this->memoized['all'])) {
            return $this->memoized['all'];
        }

        $keys = Config::get('laravel-cloudflare.cache.keys');
        $currentKey = Arr::get($keys, 'current.all', 'cloudflare:ips:current');
        $lastGoodKey = Arr::get($keys, 'last_good.all', 'cloudflare:ips:last_good');

        $current = $this->cache->get($currentKey);
        if (is_array($current) && $current !== []) {
            return $this->memoized['all'] = $current;
        }

        $fallback = $this->fallbackList($lastGoodKey, 'all');
        if ($fallback !== []) {
            return $this->memoized['all'] = $fallback;
        }

        $configFallback = $this->configFallback('all');
        if ($configFallback !== []) {
            return $this->memoized['all'] = $configFallback;
        }

        $this->logEmptyOnce('all');

        return $this->memoized['all'] = [];
    }

    /**
     * Get IPv4 ranges from cache (current -> last_good -> config fallback -> []).
     *
     * @return array<int, string>
     *
     * @throws InvalidArgumentException
     * @throws EmptyCacheException if cache is empty and throw_on_empty is enabled
     */
    public function ipv4(): array
    {
        if (isset($this->memoized['ipv4'])) {
            return $this->memoized['ipv4'];
        }

        $keys = Config::get('laravel-cloudflare.cache.keys');
        $currentKey = Arr::get($keys, 'current.v4', 'cloudflare:ips:v4:current');
        $lastGoodKey = Arr::get($keys, 'last_good.v4', 'cloudflare:ips:v4:last_good');

        $current = $this->cache->get($currentKey);
        if (is_array($current) && $current !== []) {
            return $this->memoized['ipv4'] = $current;
        }

        $fallback = $this->fallbackList($lastGoodKey, 'ipv4');
        if ($fallback !== []) {
            return $this->memoized['ipv4'] = $fallback;
        }

        $configFallback = $this->configFallback('ipv4');
        if ($configFallback !== []) {
            return $this->memoized['ipv4'] = $configFallback;
        }

        $this->logEmptyOnce('ipv4');

        return $this->memoized['ipv4'] = [];
    }

    /**
     * Get IPv6 ranges from cache (current -> last_good -> config fallback -> []).
     *
     * @return array<int, string>
     *
     * @throws EmptyCacheException if cache is empty and throw_on_empty is enabled
     */
    public function ipv6(): array
    {
        if (isset($this->memoized['ipv6'])) {
            return $this->memoized['ipv6'];
        }

        $keys = Config::get('laravel-cloudflare.cache.keys');
        $currentKey = Arr::get($keys, 'current.v6', 'cloudflare:ips:v6:current');
        $lastGoodKey = Arr::get($keys, 'last_good.v6', 'cloudflare:ips:v6:last_good');

        $current = $this->cache->get($currentKey);
        if (is_array($current) && $current !== []) {
            return $this->memoized['ipv6'] = $current;
        }

        $fallback = $this->fallbackList($lastGoodKey, 'ipv6');
        if ($fallback !== []) {
            return $this->memoized['ipv6'] = $fallback;
        }

        $configFallback = $this->configFallback('ipv6');
        if ($configFallback !== []) {
            return $this->memoized['ipv6'] = $configFallback;
        }

        $this->logEmptyOnce('ipv6');

        return $this->memoized['ipv6'] = [];
    }

    /**
     * Force refresh: fetch new lists and write to current + last_good only if successful.
     *
     * Returns true if both IPv4 and IPv6 lists were fetched (non-empty) and cached; false otherwise.
     */
    public function refresh(): bool
    {
        // Clear memoized cache to force fresh data on next call
        $this->memoized = [];

        $keys = Config::get('laravel-cloudflare.cache.keys');
        $ttl = Config::get('laravel-cloudflare.cache.ttl');

        $currentV4Key = Arr::get($keys, 'current.v4', 'cloudflare:ips:v4:current');
        $currentV6Key = Arr::get($keys, 'current.v6', 'cloudflare:ips:v6:current');
        $currentAllKey = Arr::get($keys, 'current.all', 'cloudflare:ips:current');

        $lastGoodV4Key = Arr::get($keys, 'last_good.v4', 'cloudflare:ips:v4:last_good');
        $lastGoodV6Key = Arr::get($keys, 'last_good.v6', 'cloudflare:ips:v6:last_good');
        $lastGoodAllKey = Arr::get($keys, 'last_good.all', 'cloudflare:ips:last_good');

        $newV4 = $this->fetchFromEndpoint('ipv4');
        $newV6 = $this->fetchFromEndpoint('ipv6');

        if ($newV4 === [] || $newV6 === []) {
            if (Config::get('laravel-cloudflare.logging.failed_fetch', true)) {
                Log::warning('laravel-cloudflare: refresh aborted due to empty fetch', [
                    'ipv4_empty' => $newV4 === [],
                    'ipv6_empty' => $newV6 === [],
                ]);
            }

            CloudflareRefreshFailed::dispatch($newV4 === [], $newV6 === []);

            return false;
        }

        $merged = array_values(array_unique(array_merge($newV4, $newV6)));

        // Write current with TTL
        $this->put($currentV4Key, $newV4, $ttl);
        $this->put($currentV6Key, $newV6, $ttl);
        $this->put($currentAllKey, $merged, $ttl);

        // Always update last_good (forever)
        $this->cache->forever($lastGoodV4Key, $newV4);
        $this->cache->forever($lastGoodV6Key, $newV6);
        $this->cache->forever($lastGoodAllKey, $merged);

        CloudflareIpsRefreshed::dispatch($newV4, $newV6);

        return true;
    }

    /**
     * Fetch and parse the given endpoint type.
     *
     * @param  'ipv4'|'ipv6'  $type
     * @return array<int, string>
     */
    protected function fetchFromEndpoint(string $type): array
    {
        $endpoint = Config::get("laravel-cloudflare.http.endpoints.$type");
        if (! is_string($endpoint) || $endpoint === '') {
            return [];
        }

        $timeout = (int) Config::get('laravel-cloudflare.http.timeout', 10);
        $retry = Config::get('laravel-cloudflare.http.retry', [3, 200]);
        $userAgent = Config::get('laravel-cloudflare.http.user_agent', 'ranetrace/laravel-cloudflare');

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) $userAgent,
            ])->timeout($timeout)
                ->retry((int) Arr::get($retry, 0, 3), (int) Arr::get($retry, 1, 200))
                ->get($endpoint);

            if (! $response->successful()) {
                if (Config::get('laravel-cloudflare.logging.failed_fetch', true)) {
                    Log::warning('laravel-cloudflare: failed to fetch IP ranges', [
                        'type' => $type,
                        'endpoint' => $endpoint,
                        'status' => $response->status(),
                    ]);
                }

                return [];
            }
        } catch (Throwable $e) {
            if (Config::get('laravel-cloudflare.logging.failed_fetch', true)) {
                Log::warning('laravel-cloudflare: exception while fetching IP ranges', [
                    'type' => $type,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);
            }

            return [];
        }

        $lines = preg_split('/\r?\n/', trim($response->body())) ?: [];

        // Filter comments and blank lines, then validate CIDR format
        $ips = array_values(array_filter(array_map(function (string $line): string {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                return '';
            }

            // Validate CIDR notation (basic check)
            if (! $this->isValidCidr($line)) {
                if (Config::get('laravel-cloudflare.logging.failed_fetch', true)) {
                    Log::warning('laravel-cloudflare: invalid CIDR format detected, skipping', [
                        'type' => $type,
                        'line' => $line,
                    ]);
                }

                return '';
            }

            return $line;
        }, $lines), static fn (string $v): bool => $v !== ''));

        return $ips;
    }

    /**
     * Helper to put arrays in cache with configured TTL.
     *
     * @param  array<int, string>  $value
     */
    protected function put(string $key, array $value, ?int $ttl): void
    {
        if ($ttl === null) {
            $this->cache->forever($key, $value);

            return;
        }

        $this->cache->put($key, $value, $ttl);
    }

    /**
     * Provide introspection details about current cache state without triggering network fetches.
     */
    public function cacheInfo(): array
    {
        $configKeys = Config::get('laravel-cloudflare.cache.keys', []);

        $segments = [
            'current' => [
                'v4' => Arr::get($configKeys, 'current.v4', 'cloudflare:ips:v4:current'),
                'v6' => Arr::get($configKeys, 'current.v6', 'cloudflare:ips:v6:current'),
                'all' => Arr::get($configKeys, 'current.all', 'cloudflare:ips:current'),
            ],
            'last_good' => [
                'v4' => Arr::get($configKeys, 'last_good.v4', 'cloudflare:ips:v4:last_good'),
                'v6' => Arr::get($configKeys, 'last_good.v6', 'cloudflare:ips:v6:last_good'),
                'all' => Arr::get($configKeys, 'last_good.all', 'cloudflare:ips:last_good'),
            ],
        ];

        $details = [];
        foreach ($segments as $segmentName => $keys) {
            foreach ($keys as $label => $key) {
                $present = $this->cache->has($key);
                $count = 0;
                if ($present) {
                    $value = $this->cache->get($key);
                    if (is_array($value)) {
                        $count = count($value);
                    }
                }
                $details[$segmentName][$label] = [
                    'key' => $key,
                    'present' => $present,
                    'count' => $count,
                ];
            }
        }

        $fallbackV4 = Config::get('laravel-cloudflare.fallback.ipv4', []);
        $fallbackV6 = Config::get('laravel-cloudflare.fallback.ipv6', []);

        return [
            'store' => Config::get('laravel-cloudflare.cache.store'),
            'configured_ttl' => Config::get('laravel-cloudflare.cache.ttl'),
            'allow_stale' => Config::get('laravel-cloudflare.cache.allow_stale'),
            'segments' => $details,
            'fallback' => [
                'ipv4_count' => is_array($fallbackV4) ? count($fallbackV4) : 0,
                'ipv6_count' => is_array($fallbackV6) ? count($fallbackV6) : 0,
            ],
        ];
    }

    /**
     * Attempt to get a fallback list from last_good respecting allow_stale.
     *
     * @return array<int,string>
     *
     * @throws InvalidArgumentException
     */
    protected function fallbackList(string $key, string $label): array
    {
        if (! Config::get('laravel-cloudflare.cache.allow_stale', true)) {
            return [];
        }

        $value = $this->cache->get($key);
        if (! is_array($value)) {
            return [];
        }

        // Presence alone is considered acceptable; no age enforcement.
        return $value;
    }

    /**
     * Log a warning only once per request for an empty list situation.
     *
     * @throws EmptyCacheException
     */
    protected function logEmptyOnce(string $type): void
    {
        if (Config::get('laravel-cloudflare.cache.throw_on_empty', false)) {
            throw EmptyCacheException::forType($type);
        }

        static $logged = [];
        if (isset($logged[$type])) {
            return;
        }
        $logged[$type] = true;
        Log::warning('laravel-cloudflare: no Cloudflare IP list available for '.$type.' (both current and last_good empty)');
    }

    /**
     * Get fallback IPs from config when cache is empty.
     *
     * @param  'all'|'ipv4'|'ipv6'  $type
     * @return array<int, string>
     */
    protected function configFallback(string $type): array
    {
        if ($type === 'all') {
            $v4 = Config::get('laravel-cloudflare.fallback.ipv4', []);
            $v6 = Config::get('laravel-cloudflare.fallback.ipv6', []);

            if (! is_array($v4)) {
                $v4 = [];
            }
            if (! is_array($v6)) {
                $v6 = [];
            }

            $merged = array_values(array_unique(array_merge($v4, $v6)));

            return $merged;
        }

        $key = $type === 'ipv4' ? 'ipv4' : 'ipv6';
        $fallback = Config::get("laravel-cloudflare.fallback.$key", []);

        if (! is_array($fallback)) {
            return [];
        }

        return $fallback;
    }

    /**
     * Validate if a string is in valid CIDR notation (IPv4 or IPv6).
     */
    protected function isValidCidr(string $cidr): bool
    {
        // Check for CIDR format: IP/prefix
        if (! str_contains($cidr, '/')) {
            return false;
        }

        [$ip, $prefix] = explode('/', $cidr, 2);

        // Validate prefix is numeric
        if (! is_numeric($prefix)) {
            return false;
        }

        $prefixInt = (int) $prefix;

        // Validate IP and prefix range based on IP version
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $prefixInt >= 0 && $prefixInt <= 32;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $prefixInt >= 0 && $prefixInt <= 128;
        }

        return false;
    }
}
