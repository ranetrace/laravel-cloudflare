<?php

namespace Ranetrace\LaravelCloudflare;

use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\SimpleCache\InvalidArgumentException;
use Ranetrace\LaravelCloudflare\Contracts\DurableStore;
use Ranetrace\LaravelCloudflare\Events\CloudflareIpsRefreshed;
use Ranetrace\LaravelCloudflare\Events\CloudflareRefreshFailed;
use Ranetrace\LaravelCloudflare\Exceptions\EmptyCacheException;
use Ranetrace\LaravelCloudflare\Support\FileDurableStore;
use Throwable;

class LaravelCloudflare
{
    /**
     * Runtime cache to prevent multiple cache lookups in a single request.
     *
     * @var array<string, array<int, string>>
     */
    protected array $memoized = [];

    /**
     * Package-bundled static fallback lists, lazily loaded from resources.
     *
     * @var array{ipv4: array<int, string>, ipv6: array<int, string>}|null
     */
    protected ?array $bundledFallback = null;

    /**
     * Cache repository for the volatile "current" list.
     */
    public CacheContract $cache;

    /**
     * Durable store for the "last_good" list (survives cache:clear/FLUSHDB).
     */
    public DurableStore $durable;

    public function __construct(?CacheContract $cache = null, ?DurableStore $durable = null)
    {
        // Use configured cache store or default for the volatile "current" list.
        $store = Config::get('laravel-cloudflare.cache.store');
        $this->cache = $cache ?? ($store ? Cache::store($store) : Cache::store());

        // Last-good lives in a durable store that survives cache:clear/FLUSHDB.
        $this->durable = $durable ?? FileDurableStore::fromConfig();
    }

    /**
     * Get all Cloudflare IP ranges (both IPv4 and IPv6).
     * Order: current (cache) -> last_good (durable) -> static fallback -> [] (logs if empty and allowed).
     *
     * @return array<int, string>
     *
     * @throws EmptyCacheException if everything is empty and throw_on_empty is enabled
     */
    public function all(): array
    {
        if (isset($this->memoized['all'])) {
            return $this->memoized['all'];
        }

        $keys = Config::get('laravel-cloudflare.cache.keys');
        $currentKey = Arr::get($keys, 'current.all', 'cloudflare:ips:current');

        $current = $this->cache->get($currentKey);
        if (is_array($current) && $current !== []) {
            return $this->memoized['all'] = $current;
        }

        $lastGood = $this->fallbackList('all');
        if ($lastGood !== []) {
            return $this->memoized['all'] = $lastGood;
        }

        $staticFallback = $this->staticFallback('all');
        if ($staticFallback !== []) {
            $this->logStaticFallbackInUse();

            return $this->memoized['all'] = $staticFallback;
        }

        $this->logEmptyOnce('all');

        return $this->memoized['all'] = [];
    }

    /**
     * Get IPv4 ranges. Order: current (cache) -> last_good (durable) -> static fallback -> [].
     *
     * @return array<int, string>
     *
     * @throws InvalidArgumentException
     * @throws EmptyCacheException if everything is empty and throw_on_empty is enabled
     */
    public function ipv4(): array
    {
        if (isset($this->memoized['ipv4'])) {
            return $this->memoized['ipv4'];
        }

        $keys = Config::get('laravel-cloudflare.cache.keys');
        $currentKey = Arr::get($keys, 'current.v4', 'cloudflare:ips:v4:current');

        $current = $this->cache->get($currentKey);
        if (is_array($current) && $current !== []) {
            return $this->memoized['ipv4'] = $current;
        }

        $lastGood = $this->fallbackList('ipv4');
        if ($lastGood !== []) {
            return $this->memoized['ipv4'] = $lastGood;
        }

        $staticFallback = $this->staticFallback('ipv4');
        if ($staticFallback !== []) {
            $this->logStaticFallbackInUse();

            return $this->memoized['ipv4'] = $staticFallback;
        }

        $this->logEmptyOnce('ipv4');

        return $this->memoized['ipv4'] = [];
    }

    /**
     * Get IPv6 ranges. Order: current (cache) -> last_good (durable) -> static fallback -> [].
     *
     * @return array<int, string>
     *
     * @throws EmptyCacheException if everything is empty and throw_on_empty is enabled
     */
    public function ipv6(): array
    {
        if (isset($this->memoized['ipv6'])) {
            return $this->memoized['ipv6'];
        }

        $keys = Config::get('laravel-cloudflare.cache.keys');
        $currentKey = Arr::get($keys, 'current.v6', 'cloudflare:ips:v6:current');

        $current = $this->cache->get($currentKey);
        if (is_array($current) && $current !== []) {
            return $this->memoized['ipv6'] = $current;
        }

        $lastGood = $this->fallbackList('ipv6');
        if ($lastGood !== []) {
            return $this->memoized['ipv6'] = $lastGood;
        }

        $staticFallback = $this->staticFallback('ipv6');
        if ($staticFallback !== []) {
            $this->logStaticFallbackInUse();

            return $this->memoized['ipv6'] = $staticFallback;
        }

        $this->logEmptyOnce('ipv6');

        return $this->memoized['ipv6'] = [];
    }

    /**
     * Force refresh: fetch new lists and write current (cache, TTL) + last_good (durable),
     * but only when both fetches succeed.
     *
     * Returns true if both IPv4 and IPv6 lists were fetched (non-empty) and stored; false otherwise.
     */
    public function refresh(): bool
    {
        // Clear memoized cache to force fresh data on next call.
        $this->memoized = [];

        $keys = Config::get('laravel-cloudflare.cache.keys');
        $ttl = Config::get('laravel-cloudflare.cache.ttl');

        $currentV4Key = Arr::get($keys, 'current.v4', 'cloudflare:ips:v4:current');
        $currentV6Key = Arr::get($keys, 'current.v6', 'cloudflare:ips:v6:current');
        $currentAllKey = Arr::get($keys, 'current.all', 'cloudflare:ips:current');

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

            // Leave last_good untouched so a failed refresh cannot blank the fallback.
            return false;
        }

        $merged = array_values(array_unique(array_merge($newV4, $newV6)));

        // Write current with TTL (volatile; safe to be wiped, reads fall through to last_good).
        $this->put($currentV4Key, $newV4, $ttl);
        $this->put($currentV6Key, $newV6, $ttl);
        $this->put($currentAllKey, $merged, $ttl);

        // Persist last_good durably so it survives cache:clear/FLUSHDB/deploys.
        $this->durable->putLists($newV4, $newV6, $merged);

        CloudflareIpsRefreshed::dispatch($newV4, $newV6);

        return true;
    }

    /**
     * Fetch and validate the live list for a type. Intended for the maintainer
     * cloudflare:bundle-fallback command.
     *
     * @param  'ipv4'|'ipv6'  $type
     * @return array<int, string>
     */
    public function fetchLive(string $type): array
    {
        return $this->fetchFromEndpoint($type);
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
        $ips = array_values(array_filter(array_map(function (string $line) use ($type): string {
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
     * Provide introspection details about the current cache + durable state without
     * triggering network fetches.
     *
     * @return array<string, mixed>
     */
    public function cacheInfo(): array
    {
        $configKeys = Config::get('laravel-cloudflare.cache.keys', []);

        $currentKeys = [
            'v4' => Arr::get($configKeys, 'current.v4', 'cloudflare:ips:v4:current'),
            'v6' => Arr::get($configKeys, 'current.v6', 'cloudflare:ips:v6:current'),
            'all' => Arr::get($configKeys, 'current.all', 'cloudflare:ips:current'),
        ];

        $current = [];
        foreach ($currentKeys as $label => $key) {
            $present = $this->cache->has($key);
            $count = 0;
            if ($present) {
                $value = $this->cache->get($key);
                if (is_array($value)) {
                    $count = count($value);
                }
            }
            $current[$label] = [
                'key' => $key,
                'present' => $present,
                'count' => $count,
            ];
        }

        $lastGoodLists = $this->durable->getLists();
        $lastGood = [
            'driver' => 'file',
            'location' => $this->durable->location(),
        ];
        foreach (['v4', 'v6', 'all'] as $label) {
            $list = $lastGoodLists[$label] ?? [];
            $lastGood[$label] = [
                'present' => $list !== [],
                'count' => count($list),
            ];
        }

        return [
            'store' => Config::get('laravel-cloudflare.cache.store'),
            'configured_ttl' => Config::get('laravel-cloudflare.cache.ttl'),
            'allow_stale' => Config::get('laravel-cloudflare.cache.allow_stale'),
            'segments' => [
                'current' => $current,
            ],
            'last_good' => $lastGood,
            'fallback' => [
                'ipv4_count' => count($this->staticFallback('ipv4')),
                'ipv6_count' => count($this->staticFallback('ipv6')),
            ],
        ];
    }

    /**
     * Attempt to get the last-good list from the durable store, respecting allow_stale.
     *
     * @param  'all'|'ipv4'|'ipv6'  $type
     * @return array<int, string>
     */
    protected function fallbackList(string $type): array
    {
        if (! Config::get('laravel-cloudflare.cache.allow_stale', true)) {
            return [];
        }

        return $this->durable->getList($type);
    }

    /**
     * Log a warning only once per request for a fully empty list situation.
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
        Log::warning('laravel-cloudflare: no Cloudflare IP list available for '.$type.' (current, last_good and static fallback all empty)');
    }

    /**
     * Emit a throttled warning whenever the static fallback layer is actually served.
     *
     * Reaching this layer means current and last_good are both empty, i.e. the refresh
     * pipeline is not keeping the lists up to date. The throttle marker lives in the
     * durable store so the warning survives cache clears rather than re-firing endlessly.
     */
    protected function logStaticFallbackInUse(): void
    {
        if (! Config::get('laravel-cloudflare.logging.static_fallback', true)) {
            return;
        }

        $throttle = (int) Config::get('laravel-cloudflare.logging.static_fallback_throttle', 3600);
        $now = now()->getTimestamp();
        $last = $this->durable->throttledAt('static_fallback');

        if ($last !== null && $throttle > 0 && ($now - $last) < $throttle) {
            return;
        }

        $this->durable->markThrottled('static_fallback', $now);

        Log::warning('laravel-cloudflare: serving static fallback Cloudflare IPs — the refresh pipeline may be broken (run cloudflare:refresh and verify your scheduler)');
    }

    /**
     * Resolve the static fallback for a type: published config when non-empty,
     * otherwise the package-bundled list. Never sourced from the mergeable config
     * array, so a published-but-emptied `fallback` cannot blank it.
     *
     * @param  'all'|'ipv4'|'ipv6'  $type
     * @return array<int, string>
     */
    protected function staticFallback(string $type): array
    {
        if ($type === 'all') {
            $v4 = $this->staticFallback('ipv4');
            $v6 = $this->staticFallback('ipv6');

            return array_values(array_unique(array_merge($v4, $v6)));
        }

        $key = $type === 'ipv4' ? 'ipv4' : 'ipv6';

        $configured = Config::get("laravel-cloudflare.fallback.$key", []);
        if (is_array($configured) && $configured !== []) {
            return array_values(array_filter($configured, 'is_string'));
        }

        return $this->bundledFallback($key);
    }

    /**
     * Read the authoritative package-bundled fallback shipped in resources/.
     *
     * @param  'ipv4'|'ipv6'  $key
     * @return array<int, string>
     */
    protected function bundledFallback(string $key): array
    {
        if ($this->bundledFallback === null) {
            $path = __DIR__.'/../resources/cloudflare-ips.php';
            $data = is_file($path) ? require $path : [];

            $this->bundledFallback = [
                'ipv4' => $this->normalizeBundled($data, 'ipv4'),
                'ipv6' => $this->normalizeBundled($data, 'ipv6'),
            ];
        }

        return $this->bundledFallback[$key];
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeBundled(mixed $data, string $key): array
    {
        if (! is_array($data) || ! isset($data[$key]) || ! is_array($data[$key])) {
            return [];
        }

        return array_values(array_filter($data[$key], 'is_string'));
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
