# Laravel Cloudflare

[![Latest Version](https://img.shields.io/packagist/v/ranetrace/laravel-cloudflare.svg)](https://packagist.org/packages/ranetrace/laravel-cloudflare)
[![Tests](https://img.shields.io/github/actions/workflow/status/ranetrace/laravel-cloudflare/laravel-package-tests.yml?branch=main&label=tests)](https://github.com/ranetrace/laravel-cloudflare/actions/workflows/laravel-package-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/ranetrace/laravel-cloudflare.svg)](https://packagist.org/packages/ranetrace/laravel-cloudflare)

Retrieve the current Cloudflare IP ranges, cache them, automatically update them, and access them through a simple service. 

Use the IP list in your `TrustProxies` middleware to trust all Cloudflare IPs automatically.

## Installation

Install the package via composer:

```bash
composer require ranetrace/laravel-cloudflare
```

(Optional) Publish the config file:

```bash
php artisan vendor:publish --tag="laravel-cloudflare"
```

## Laravel Boost Integration

This package includes built-in support for [Laravel Boost](https://github.com/laravel/boost), providing AI coding agents with context about how to use and configure this package.

When you run `php artisan boost:install` in a project that has this package installed:

- **A minimal guideline** is loaded with a quick command reference
- **The `laravel-cloudflare-setup` skill** becomes available for on-demand installation assistance, including the complete setup process, `bootstrap/app.php` middleware configuration, troubleshooting, and API reference

This approach keeps context overhead minimal while providing comprehensive help when needed. No additional configuration is required - Boost automatically discovers these resources from the package.

## Configuration

Content of the config file:

```php
return [
    'cache' => [
        // Cache store to use for the volatile "current" list (null = default store)
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

        // Throw instead of returning [] when current, last_good and static fallback are all empty.
        'throw_on_empty' => env('CLOUDFLARE_THROW_ON_EMPTY', false),
    ],

    // Durable "last_good" store. Unlike the cache, this survives cache:clear/FLUSHDB
    // (e.g. another app flushing a shared Redis) and deploys when storage/ is shared.
    'last_good' => [
        'path' => env('CLOUDFLARE_LAST_GOOD_PATH', storage_path('laravel-cloudflare/last_good.json')),
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

        // Warn (throttled, persisted durably) when the static fallback layer is actually
        // served — reaching it means current AND last_good are empty.
        'static_fallback' => env('CLOUDFLARE_LOG_STATIC_FALLBACK', true),
        'static_fallback_throttle' => env('CLOUDFLARE_STATIC_FALLBACK_THROTTLE', 60 * 60),
    ],

    // Static fallback IPs, used only as a cold-start floor before the first refresh.
    // Leave EMPTY to use the authoritative list bundled with the package
    // (resources/cloudflare-ips.php). A non-empty list here overrides the bundled defaults.
    'fallback' => [
        'ipv4' => [],
        'ipv6' => [],
    ],

    'diagnostics' => [
        // Enable the diagnostics route (default: false)
        'enabled' => env('CLOUDFLARE_DIAGNOSTICS_ENABLED', false),

        // Path for the diagnostics route
        'path' => env('CLOUDFLARE_DIAGNOSTICS_PATH', '/cloudflare-diagnose'),
    ],
];
```

## What it does

- Fetches Cloudflare IP ranges from:
	- https://www.cloudflare.com/ips-v4
	- https://www.cloudflare.com/ips-v6
- Validates IP ranges in CIDR notation before caching
- Caches the `current` list (default 7 days) and keeps a durable `last_good` copy outside the cache (a JSON file under `storage/`) that survives `cache:clear`/`FLUSHDB`/deploys
- Ships a package-bundled static fallback so a brand-new install always has IPs to trust before the first refresh
- Provides commands to manage the cache:
    - `php artisan cloudflare:refresh` - fetch and cache the latest IPs
    - `php artisan cloudflare:cache-info` - view cache status (supports `--json` flag)
    - `php artisan cloudflare:clear` - clear cached IPs
- Dispatches events for extensibility:
    - `CloudflareIpsRefreshed` - fired when IPs are successfully refreshed
    - `CloudflareRefreshFailed` - fired when refresh fails
- Interact with the lists in your code via the `LaravelCloudflare` service:
    - `ipv4()`: get IPv4 addresses
    - `ipv6()`: get IPv6 addresses
    - `all()`: get all addresses (v4 + v6)
    - `refresh()`: fetch and cache immediately (returns bool success)
    - `cacheInfo()`: get info about the cached lists

## Quick usage

The most common use case is to trust Cloudflare proxies in your application.

1. Run the following command to fetch and cache the IPs initially:

```bash
php artisan cloudflare:refresh
```

2. Register the refresh command to your application's scheduler (`routes/console.php`):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('cloudflare:refresh')->twiceDaily(); // or ->daily(), ->hourly(), etc.
```

3. Trust Cloudflare proxies in `bootstrap/app.php`, by putting the package's middleware in the place of the framework's:

```php
use Illuminate\Http\Middleware\TrustProxies;
use Ranetrace\LaravelCloudflare\Http\Middleware\TrustCloudflareProxies;

->withMiddleware(function (Middleware $middleware) {
    // Your other middleware interactions here...

    $middleware->replace(TrustProxies::class, TrustCloudflareProxies::class);
})
```

That is the whole wiring. `TrustCloudflareProxies` reads the list while it handles a request, so no part of booting the application depends on the cache being reachable. Reading it at boot instead — what earlier versions of this README recommended, inside an `app()->booted()` callback handed to `trustProxies(at: ...)` — makes a working cache store a condition of every `php artisan` invocation, and there is one without a working cache in every checkout: `composer install` runs `package:discover` before a `.env` exists, so `CACHE_STORE` falls back to `database` and the read hits a database nobody has created yet.

Proxies to trust besides Cloudflare go in `trust_proxies.additional` (see [Determine which proxies to trust besides Cloudflare](#determine-which-proxies-to-trust-besides-cloudflare)). Everything else the framework's `TrustProxies` does, including the header set it honors, is inherited untouched.

4. Use the `cache-info` command to see information about the currently cached IPs.

```bash
php artisan cloudflare:cache-info
```

5. Enable the diagnostics route (optional) by setting `CLOUDFLARE_DIAGNOSTICS_ENABLED=true` in your `.env` file. Then visit `/cloudflare-diagnose` in your app to see how Cloudflare and your server headers are interpreted by Laravel.

6. If the `laravel_ip` in the diagnostics output from step 5 does not show your real client IP, read section [Determine which proxies to trust besides Cloudflare](#determine-which-proxies-to-trust-besides-cloudflare).

## The LaravelCloudflare service

```php
use Ranetrace\LaravelCloudflare\LaravelCloudflare;

$cloudflare = app(LaravelCloudflare::class);
$cloudflare->refresh(); // fetch and cache immediately
$v4Ips = $cloudflare->ipv4();
$v6Ips = $cloudflare->ipv6();
$allIps = $cloudflare->all();
$cacheInfo = $cloudflare->cacheInfo();
```

## Caching design (current + durable last_good)

To avoid network calls during request handling and still remain resilient if Cloudflare is temporarily unreachable, the package maintains layered storage:

* **current** – the actively refreshed list, held in the cache with a configurable TTL (default 7 days). It is fine for this to be wiped: reads fall through to last_good.
* **last_good** – a copy updated only after a successful refresh, persisted in a **durable store outside the cache** (a JSON file under `storage/`, path configurable). It is not cleared on a failed refresh, and — unlike the cache — it survives `cache:clear`/`FLUSHDB` (e.g. another app flushing a shared Redis) and deploys when `storage/` is shared between releases.

> **Why durable?** Earlier versions kept last_good in the same cache store as current. That meant a single `cache:clear` (which is `FLUSHDB` on the Redis driver, ignoring key prefixes) could wipe *both* layers at once, and with an empty static fallback the package would hand `trustProxies()` an empty list. Keeping last_good outside the cache removes that failure mode.

Lookup order for `ipv4()`, `ipv6()`, and `all()`:
1. current list (cache)
2. last_good list (durable store)
3. static fallback (published config if non-empty, else the package-bundled list)
4. (logs a warning and returns an empty array only if all of the above are empty – effectively never, since the bundled fallback ships non-empty)

Relevant config options (`config/laravel-cloudflare.php`):
* `cache.ttl` – lifetime for the current list (seconds, null = forever).
* `cache.allow_stale` – whether to fall back to last_good when current is missing.
* `cache.throw_on_empty` – throw an exception instead of returning an empty array when everything is empty (default: false).
* `last_good.path` – absolute path to the durable last_good JSON file (`CLOUDFLARE_LAST_GOOD_PATH`).
* `logging.static_fallback` / `logging.static_fallback_throttle` – warn (throttled) when the static fallback layer is actually served.
* `fallback.ipv4` and `fallback.ipv6` – optional static IP arrays that override the bundled defaults (see below).

Operational recommendation:
* Run `cloudflare:refresh` in your deployment pipeline and via the scheduler.
* Keep `storage/` shared between releases (the default on Forge/Envoyer/Deployer) so last_good carries across deploys.
* The durable file is regenerated runtime data — add `storage/laravel-cloudflare/` to your `.gitignore`.
* Regularly check logs and use `cloudflare:cache-info` to monitor cache + durable status.

## Static fallback IPs

The static fallback is the cold-start floor: it is used only when both `current` and `last_good` are empty (e.g. on a brand-new install before the first `cloudflare:refresh`). It ensures your app always has IPs to trust, even on first deployment.

**You do not need to configure anything.** The package ships an authoritative fallback list (`resources/cloudflare-ips.php`) that is used automatically. The published config keeps `fallback.ipv4/ipv6` empty, which means *"use the bundled defaults"* — so there is no stale list rotting in your repo.

If you want to pin your own ranges instead, set a non-empty list; a non-empty value overrides the bundled defaults for that type:

```php
// In config/laravel-cloudflare.php
'fallback' => [
    'ipv4' => [
        '173.245.48.0/20',
        '103.21.244.0/22',
        // ... empty = use the package-bundled list
    ],
    'ipv6' => [
        '2400:cb00::/32',
        // ... empty = use the package-bundled list
    ],
],
```

**Log-on-use:** whenever the static fallback is the layer actually served, the package emits a throttled (default once/hour) warning — *"serving static fallback Cloudflare IPs — the refresh pipeline may be broken"*. Reaching this layer means `current` and `last_good` are both empty, so it is a signal to check your scheduler/`cloudflare:refresh`, not a normal steady state. The throttle marker is persisted in the durable store so it survives cache clears.

### Maintainers: regenerating the bundled list

The bundled list is refreshed from the live Cloudflare endpoints with a maintainer command, run from the package repo before tagging a release:

```bash
php artisan cloudflare:bundle-fallback
```

This rewrites `resources/cloudflare-ips.php`. It is a dev/release tool and is not intended to be run inside host applications.

## Diagnostics route (optional)

You can expose a small diagnostics endpoint to see how Cloudflare and your server headers are interpreted by Laravel.

**⚠️ SECURITY WARNING**: The diagnostics endpoint exposes information about your proxy configuration. By default, it uses only the `web` middleware without authentication. For production environments, you should:
- Keep it disabled (`CLOUDFLARE_DIAGNOSTICS_ENABLED=false`)
- Or add authentication middleware in the config file
- Or restrict access by IP address
- Or only enable it in development/staging environments

- Enable it via env/config:
    - `CLOUDFLARE_DIAGNOSTICS_ENABLED=true`
    - Optional custom path: `CLOUDFLARE_DIAGNOSTICS_PATH=/cloudflare-diagnose` (default is `/cloudflare-diagnose`)
    - Optional middleware: Configure in `config/laravel-cloudflare.php` (e.g., `['web', 'auth']`)
- When enabled, a GET endpoint is registered at the configured path and returns JSON like:

```json
{
    "laravel_ip": "203.0.113.5",
    "remote_addr": "172.16.0.10",
    "x_forwarded_for": "203.0.113.5, 172.16.0.10",
    "cf_connecting_ip": "203.0.113.5",
    "true_client_ip": "203.0.113.5",
    "server_https": "on",
    "is_secure": true
}
```

How to interpret:
- `laravel_ip`: The client IP as seen by Laravel after processing trusted proxies (i.e., the effective client IP).
- `remote_addr`: The direct connection IP (usually your load balancer or Cloudflare).
- `x_forwarded_for`: The full X-Forwarded-For header (may contain multiple IPs).
- `cf_connecting_ip`: The Cloudflare-specific header containing the original client IP (if present).
- `true_client_ip`: The True-Client-IP header (if present).
- `server_https`: The raw HTTPS server variable.
- `is_secure`: Whether Laravel considers the request secure (HTTPS).

If setup correctly, `laravel_ip` should match the actual client IP instead of a Cloudflare IP.

## Events

The package dispatches events that you can listen to for custom logic:

### CloudflareIpsRefreshed

Fired when IP ranges are successfully fetched and cached.

```php
use Ranetrace\LaravelCloudflare\Events\CloudflareIpsRefreshed;

// In your EventServiceProvider or listener
Event::listen(function (CloudflareIpsRefreshed $event) {
    // $event->ipv4 - array of IPv4 ranges
    // $event->ipv6 - array of IPv6 ranges

    Log::info('Cloudflare IPs refreshed', [
        'ipv4_count' => count($event->ipv4),
        'ipv6_count' => count($event->ipv6),
    ]);
});
```

### CloudflareRefreshFailed

Fired when the refresh operation fails (e.g., network error, empty response).

```php
use Ranetrace\LaravelCloudflare\Events\CloudflareRefreshFailed;

Event::listen(function (CloudflareRefreshFailed $event) {
    // $event->ipv4Empty - bool indicating if IPv4 fetch failed
    // $event->ipv6Empty - bool indicating if IPv6 fetch failed

    // Send alert to monitoring service
    if ($event->ipv4Empty && $event->ipv6Empty) {
        // Both failed - critical
    }
});
```

## Commands

### cloudflare:refresh

Fetches the latest IP ranges from Cloudflare and caches them. Returns exit code 0 on success, 1 on failure.

```bash
php artisan cloudflare:refresh
```

### cloudflare:cache-info

Displays information about the currently cached IP ranges.

```bash
# Human-readable output
php artisan cloudflare:cache-info

# JSON output for scripting
php artisan cloudflare:cache-info --json
```

### cloudflare:clear

Clears stored IP ranges. Useful for testing or troubleshooting.

```bash
# Clear everything (current cache and the durable last_good store)
php artisan cloudflare:clear

# Clear only the current cache
php artisan cloudflare:clear --current

# Clear only the durable last_good store
php artisan cloudflare:clear --last-good
```

### cloudflare:bundle-fallback

**Maintainer tool.** Fetches the live Cloudflare ranges and rewrites the package-bundled fallback (`resources/cloudflare-ips.php`). Run from the package repo before tagging a release; it is not meant for host apps.

```bash
php artisan cloudflare:bundle-fallback
```

## Why trusting proxies is important

Most production Laravel apps sit behind one or more proxies (CDNs, load balancers, etc.). Those proxies terminate TLS and forward the request to your app, typically attaching standard forwarding headers such as X-Forwarded-For/Proto/Host/Port.

Laravel will only use these headers if the request comes from a proxy you have explicitly trusted. Otherwise, Laravel ignores the headers (to prevent spoofing) and falls back to the direct connection details (REMOTE_ADDR, plain HTTP scheme, internal host/port).

When proxies are not trusted, several things can go wrong:

- Client IP is incorrect
    - `Request::ip()` shows the proxy or 127.0.0.1 instead of the real client.
    - Side effects: rate limiting and abuse protection over/under throttle, allow/deny lists misfire, audit logs and analytics record the wrong IP.

- HTTPS awareness is lost
    - `Request::isSecure()` may be false even when the original request was HTTPS.
    - Side effects: generated links use `http://` (mixed content), “force HTTPS” logic misbehaves, and cookies that require the `Secure` flag (e.g., SameSite=None) may be dropped by browsers, impacting auth / sessions.

- Host and port are wrong
    - Generated URLs (redirects, emails, pagination), signed URLs, and callback URLs may be invalid because they use internal host/port instead of the public ones.
    - Domain / subdomain routing or multi-tenant routing based on host can mis-route.

Trusting your proxies tells Laravel which upstream IPs/CIDRs are allowed to supply forwarding headers and which header set to honor. Then, Laravel normalizes the request's effective IP, scheme, host, and port. Thereby mitigating the above issues.

For security, avoid trusting all proxies unless your app is only reachable through a trusted network perimeter. Trusting the wrong IPs lets attackers spoof forwarding headers.

## Determine which proxies to trust besides Cloudflare

In addition to Cloudflare IPs, it's sometimes necessary to trust other proxies that forward traffic to your app.

- Receiving traffic via Cloudflare? Include the Cloudflare IP ranges from this package.
- Running a local web server in front of your app (e.g., Nginx → Octane)? Also include the local upstreams, commonly `127.0.0.1` and `::1`.
- Using a load balancer or ingress? Include its IP/CIDR (or the local web server that fronts your app).

List those extra hops in `trust_proxies.additional`; `TrustCloudflareProxies` trusts them alongside the Cloudflare ranges:

```php
// config/laravel-cloudflare.php
'trust_proxies' => [
    'additional' => ['127.0.0.1', '::1'],
],
```

Quick check: enable `CLOUDFLARE_DIAGNOSTICS_ENABLED=true` and visit `/cloudflare-diagnose`. If `laravel_ip` shows your real client IP and `is_secure` is true for HTTPS, you're set.

Security tip: trust only the proxies that truly forward traffic to your app; avoid `'*'` on public apps.

## Using with Laravel Octane

When you use this package to trust Cloudflare proxies via the `TrustCloudflareProxies` middleware, while running behind Laravel Octane, keep the following in mind:

The `LaravelCloudflare` service is a singleton that memoizes the list after the first read, and under Octane a singleton lives as long as the worker does. So the list is read once per worker and then held in memory until the worker restarts.

Result: after you run `php artisan cloudflare:refresh`, workers do not immediately see the refreshed IP list.

Usually this is fine because:
- Cloudflare IP ranges rarely change.
- Octane workers restart after serving 500 requests by default.
- Octane workers restart when the application is deployed.

It can be a problem when:
- Your Octane workers do not restart soon enough for your needs (e.g., low traffic, high max requests setting, many workers).
- You want to always have the latest IPs in your Octane workers, no matter what.

If either applies:
- Restart the Octane workers with `php artisan octane:restart` after running `php artisan cloudflare:refresh`.

See the Laravel Octane documentation for more details: https://laravel.com/docs/octane

## Security Considerations

### Cache & Durable Store Security

The IP ranges stored by this package become your trusted proxy list. If an attacker can compromise your cache store (Redis, Memcached, etc.) **or write to the durable `last_good` file** (`storage/laravel-cloudflare/last_good.json`), they could inject malicious IPs into the trusted proxy list, allowing them to spoof forwarding headers.

**Recommendations:**
- Secure your cache store with proper authentication and network isolation
- Use a dedicated cache store for security-critical data if needed
- Keep `storage/` writable only by the application user and protect it like any other application secret
- Monitor cache and filesystem access logs for suspicious activity
- Document this risk in your security procedures

### IP Validation

The package validates that fetched IPs are in valid CIDR notation before caching. Invalid entries are logged and skipped. However, this is a format check only - it doesn't verify that the IPs are actually owned by Cloudflare.

### Exception Handling

By default, when the cache is empty (both current and last_good), methods return an empty array and log a warning. This could silently break proxy trust configuration.

To fail fast instead:
```php
// In config/laravel-cloudflare.php or .env
'throw_on_empty' => true, // or CLOUDFLARE_THROW_ON_EMPTY=true
```

When enabled, `all()`, `ipv4()`, and `ipv6()` will throw `EmptyCacheException` if no cached data is available.

### Diagnostics Endpoint

See the [Diagnostics route section](#diagnostics-route-optional) for important security warnings about this feature.

## License

Licensed under the MIT License. See `LICENSE.md`.
