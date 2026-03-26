<?php

return [
    'cache' => [
        // Cache store to use (null = default store)
        'store' => env('CLOUDFLARE_CACHE_STORE', null),

        // Primary cache keys ("current" – refreshed list) and fallback ("last_good" – permanent)
        'keys' => [
            'current' => [
                'all' => 'cloudflare:ips:current',
                'v4' => 'cloudflare:ips:v4:current',
                'v6' => 'cloudflare:ips:v6:current',
            ],
            'last_good' => [
                'all' => 'cloudflare:ips:last_good',
                'v4' => 'cloudflare:ips:v4:last_good',
                'v6' => 'cloudflare:ips:v6:last_good',
            ],
        ],

        // Time to live in seconds for the "current" list (null = forever). Default: 7 days.
        'ttl' => env('CLOUDFLARE_CACHE_TTL', 60 * 60 * 24 * 7),

        // Allow falling back to the last known good list when current is missing/expired.
        'allow_stale' => env('CLOUDFLARE_ALLOW_STALE', true),

        // Throw exception when cache is empty (both current and last_good) instead of returning empty array
        'throw_on_empty' => env('CLOUDFLARE_THROW_ON_EMPTY', false),
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
    ],

    // Static fallback IPs to use when cache is empty (both current and last_good).
    // This is useful as a safety net to ensure your app always has IPs to trust,
    // even before the first cloudflare:refresh runs.
    // You can populate these with current Cloudflare IPs from https://www.cloudflare.com/ips/
    'fallback' => [
        'ipv4' => [
            // Example: '173.245.48.0/20', '103.21.244.0/22', ...
        ],
        'ipv6' => [
            // Example: '2400:cb00::/32', '2606:4700::/32', ...
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
