<?php

return [
    'cache' => [
        // Cache store to use for the volatile "current" list (null = default store).
        'store' => env('CLOUDFLARE_CACHE_STORE', null),

        // Cache keys for the "current" (refreshed, TTL'd) list.
        // NOTE: last_good no longer lives in the cache; see the "last_good" section below.
        'keys' => [
            'current' => [
                'all' => 'cloudflare:ips:current',
                'v4' => 'cloudflare:ips:v4:current',
                'v6' => 'cloudflare:ips:v6:current',
            ],
        ],

        // Time to live in seconds for the "current" list (null = forever). Default: 7 days.
        'ttl' => env('CLOUDFLARE_CACHE_TTL', 60 * 60 * 24 * 7),

        // Allow falling back to the last known good list when current is missing/expired.
        'allow_stale' => env('CLOUDFLARE_ALLOW_STALE', true),

        // Throw exception when everything is empty (current, last_good and static fallback)
        // instead of returning an empty array.
        'throw_on_empty' => env('CLOUDFLARE_THROW_ON_EMPTY', false),
    ],

    // Durable "last_good" store. Unlike the cache, this survives cache:clear/FLUSHDB
    // (e.g. another app flushing a shared Redis) and deploys when storage/ is shared,
    // so it can actually act as a fallback after the volatile cache is wiped.
    'last_good' => [
        // Absolute path to the JSON file holding the last known good lists.
        'path' => env('CLOUDFLARE_LAST_GOOD_PATH', storage_path('laravel-cloudflare/last_good.json')),
    ],

    // Read by the TrustCloudflareProxies middleware.
    'trust_proxies' => [
        // Proxies to trust besides Cloudflare's ranges: the hops that actually
        // forward traffic to this application, such as a local web server
        // (Nginx in front of Octane: '127.0.0.1', '::1'), a load balancer or an
        // ingress. Everything listed here may supply forwarding headers, so
        // keep it to the hops you control.
        'additional' => [],
    ],

    // HTTP client settings for fetching IP ranges from Cloudflare
    'http' => [
        'timeout' => env('CLOUDFLARE_HTTP_TIMEOUT', 10), // seconds
        // [attempts, sleepMilliseconds]
        'retry' => [env('CLOUDFLARE_HTTP_RETRY_ATTEMPTS', 3), env('CLOUDFLARE_HTTP_RETRY_SLEEP', 200)],
        'user_agent' => env('CLOUDFLARE_HTTP_USER_AGENT', 'Laravel-Cloudflare-IP-Fetcher/1.0 (+https://github.com/ranetrace/laravel-cloudflare)'),
        'endpoints' => [
            'ipv4' => 'https://www.cloudflare.com/ips-v4',
            'ipv6' => 'https://www.cloudflare.com/ips-v6',
        ],
    ],

    'logging' => [
        // Whether to log a warning when a fetch to Cloudflare endpoints fails
        'failed_fetch' => env('CLOUDFLARE_LOG_FAILED_FETCH', true),

        // Whether to warn when the static fallback layer is actually served. Reaching it
        // means current AND last_good are empty (the refresh pipeline is likely broken).
        'static_fallback' => env('CLOUDFLARE_LOG_STATIC_FALLBACK', true),

        // Throttle window (seconds) for the static-fallback warning, persisted durably.
        'static_fallback_throttle' => env('CLOUDFLARE_STATIC_FALLBACK_THROTTLE', 60 * 60),
    ],

    // Static fallback IPs used only as a cold-start floor, when both cache layers
    // (current and last_good) are empty — typically only on a brand-new install
    // before the first cloudflare:refresh runs.
    //
    // Leave these EMPTY to use the authoritative list bundled with the package
    // (resources/cloudflare-ips.php). Set them only if you want to pin your own
    // ranges; a non-empty list here overrides the bundled defaults for that type.
    'fallback' => [
        'ipv4' => [
            // empty = use the package-bundled Cloudflare ranges
        ],
        'ipv6' => [
            // empty = use the package-bundled Cloudflare ranges
        ],
    ],

    'diagnostics' => [
        // Enable the diagnostics route (default: false)
        'enabled' => env('CLOUDFLARE_DIAGNOSTICS_ENABLED', false),

        // Path for the diagnostics route
        'path' => env('CLOUDFLARE_DIAGNOSTICS_PATH', '/cloudflare-diagnose'),

        // Middleware to apply to the diagnostics route (e.g., ['auth', 'can:view-diagnostics'])
        // IMPORTANT: For security, add authentication middleware in production
        'middleware' => ['web'],
    ],
];
