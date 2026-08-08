---
name: laravel-cloudflare-setup
description: Install and configure Laravel Cloudflare for proxy trust with Cloudflare CDN.
---

# Laravel Cloudflare Setup

## When to use this skill

Use this skill when:
- Installing the laravel-cloudflare package
- Configuring TrustProxies middleware for Cloudflare
- Setting up automated IP refresh scheduling
- Debugging proxy or IP detection issues

## Installation Steps

### 1. Install the package

```bash
composer require ranetrace/laravel-cloudflare
```

### 2. Publish configuration (optional)

```bash
php artisan vendor:publish --tag="laravel-cloudflare"
```

### 3. Fetch IPs initially

```bash
php artisan cloudflare:refresh
```

### 4. Configure TrustProxies middleware

In `bootstrap/app.php`, put the package's middleware in the place of the framework's:

```php
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Ranetrace\LaravelCloudflare\Http\Middleware\TrustCloudflareProxies;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(/* ... */)
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->replace(TrustProxies::class, TrustCloudflareProxies::class);
    })
    ->create();
```

**Important**: Never resolve the IP list at boot — not in an `app()->booted()` callback handed to `trustProxies(at: ...)`, which earlier versions of this package recommended, and not anywhere else in `bootstrap/app.php`. The list comes from the cache, and a boot that needs the cache fails wherever the cache is not reachable yet: `composer install` runs `package:discover` before a `.env` exists, so `CACHE_STORE` falls back to `database` and every `php artisan` invocation dies on a database nobody has created. `TrustCloudflareProxies` reads the list while handling a request, the only moment proxy trust is needed.

Proxies to trust besides Cloudflare (a local web server, a load balancer) go in `laravel-cloudflare.trust_proxies.additional`, not in a second `trustProxies(at: ...)` call — this middleware does not consult it.

### 5. Schedule automatic refreshes

In `routes/console.php`, add:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('cloudflare:refresh')->twiceDaily();
```

This keeps the cached IP list up-to-date as Cloudflare occasionally updates their ranges.

## Verification

After setup, verify the configuration:

```bash
# Check cache status
php artisan cloudflare:cache-info

# Should show cached IPv4 and IPv6 counts
```

## Artisan Commands

| Command | Description |
|---------|-------------|
| `cloudflare:refresh` | Fetch and cache latest IPs from Cloudflare |
| `cloudflare:cache-info` | Display cache + durable last_good status (supports `--json` flag) |
| `cloudflare:clear` | Clear current cache and/or durable last_good (`--current` / `--last-good`) |
| `cloudflare:bundle-fallback` | Maintainer-only: regenerate the package-bundled fallback (run from the package repo, not host apps) |

## Programmatic API

Access IPs via the facade or service:

```php
use Ranetrace\LaravelCloudflare\Facades\LaravelCloudflare;

$allIps = LaravelCloudflare::all();      // Combined IPv4 + IPv6 array
$ipv4Only = LaravelCloudflare::ipv4();   // IPv4 ranges only
$ipv6Only = LaravelCloudflare::ipv6();   // IPv6 ranges only
$success = LaravelCloudflare::refresh(); // Fetch and cache immediately
$info = LaravelCloudflare::cacheInfo();  // Get cache status
```

`all()`, `ipv4()` and `ipv6()` answer through four layers — cache, durable `last_good`, package-bundled ranges, `[]` — and a cache that is empty or a store that cannot be read at all falls through them with a throttled warning instead of throwing. Do not wrap these calls in `try`/`catch`, check the cache first, or keep a fallback list of your own; that is what the layers are. (`cache.throw_on_empty` is the one opt-in that makes them throw, and only when every layer is empty.)

`refresh()` and `cacheInfo()` do surface a broken store, by design: one is writing to it and the other is reporting on it.

## Events

Listen to these events for monitoring or custom logic:

| Event | When | Properties |
|-------|------|------------|
| `CloudflareIpsRefreshed` | Successful refresh | `ipv4`, `ipv6` (arrays) |
| `CloudflareRefreshFailed` | Failed refresh | `ipv4Empty`, `ipv6Empty` (booleans) |

Example listener:

```php
use Ranetrace\LaravelCloudflare\Events\CloudflareRefreshFailed;

Event::listen(function (CloudflareRefreshFailed $event) {
    if ($event->ipv4Empty && $event->ipv6Empty) {
        // Alert: complete refresh failure
    }
});
```

## Configuration Options

Key settings in `config/laravel-cloudflare.php`:

| Setting | Default | Description |
|---------|---------|-------------|
| `cache.store` | `null` | Cache store for the volatile `current` list (null = default) |
| `cache.ttl` | 7 days | Cache duration in seconds for `current` |
| `cache.allow_stale` | `true` | Fall back to durable last_good when `current` is missing |
| `trust_proxies.additional` | `[]` | Proxies `TrustCloudflareProxies` trusts besides Cloudflare's ranges |
| `logging.unreachable_cache` | `true` | Warn (throttled) when the cache store cannot be read at all |
| `last_good.path` | `storage_path('laravel-cloudflare/last_good.json')` | Durable last_good file (survives cache:clear/FLUSHDB) |
| `fallback.ipv4` | `[]` | Override the bundled IPv4 fallback (empty = use bundled defaults) |
| `fallback.ipv6` | `[]` | Override the bundled IPv6 fallback (empty = use bundled defaults) |
| `diagnostics.enabled` | `false` | Enable debug endpoint |

## Diagnostics (Optional)

For debugging proxy issues, enable the diagnostics endpoint:

**In `.env`:**
```
CLOUDFLARE_DIAGNOSTICS_ENABLED=true
```

**Or in `config/laravel-cloudflare.php`:**
```php
'diagnostics' => [
    'enabled' => true,
    'path' => '/cloudflare-diagnose',
    'middleware' => ['web'],
],
```

Then visit `/cloudflare-diagnose` to see:
- `laravel_ip` - The IP Laravel sees after proxy trust
- `remote_addr` - Direct connection IP
- `x_forwarded_for` - Forwarding chain
- `cf_connecting_ip` - Cloudflare's client IP header

If `laravel_ip` matches `cf_connecting_ip`, proxy trust is configured correctly.

## Troubleshooting

### IPs not loading / empty array

1. Run `php artisan cloudflare:refresh` to fetch IPs
2. Check `php artisan cloudflare:cache-info` for cache status
3. Verify cache driver is working: `php artisan cache:clear && php artisan cloudflare:refresh`

### Log says "the cache store could not be read"

The cache store is down or not set up (an unmigrated `database` store, a Redis that is not answering). Reads are unaffected in the sense that they still return a list — they fall through to `last_good` and the bundled ranges — so this warning is the only signal that the top layer is gone. It is throttled to once an hour (`logging.unreachable_cache_throttle`) and can be silenced with `logging.unreachable_cache`, though the store is the thing to fix.

Run `php artisan cloudflare:cache-info` or `php artisan cloudflare:refresh` to see the underlying store error: unlike the read path, those two do not swallow it.

### Client IP still shows Cloudflare IP

1. Ensure `bootstrap/app.php` replaces `TrustProxies` with `TrustCloudflareProxies`
2. Check that `X-Forwarded-For` header is being sent (use diagnostics endpoint)
3. Verify the request is actually coming through Cloudflare
4. If another proxy sits between Cloudflare and the app, add it to `trust_proxies.additional`

### First deployment with empty cache

No action needed: the package ships an authoritative bundled fallback (`resources/cloudflare-ips.php`) that is served automatically until the first `cloudflare:refresh` runs. Leave `fallback.ipv4/ipv6` empty to use it.

Only set `fallback.ipv4/ipv6` in `config/laravel-cloudflare.php` if you want to pin your own ranges; a non-empty list overrides the bundled defaults for that type. If you see a throttled *"serving static fallback Cloudflare IPs"* warning in your logs, your refresh pipeline (scheduler/`cloudflare:refresh`) is not running — fix that rather than relying on the fallback.
