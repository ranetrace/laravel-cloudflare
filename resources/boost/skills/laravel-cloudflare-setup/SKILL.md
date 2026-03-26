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

In `bootstrap/app.php`, add the Cloudflare IPs to the trusted proxies configuration:

```php
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(/* ... */)
    ->withMiddleware(function (Middleware $middleware) {
        app()->booted(function () use ($middleware) {
            $cloudflareIps = app(\Ranetrace\LaravelCloudflare\LaravelCloudflare::class)->all();
            $middleware->trustProxies(at: $cloudflareIps);
        });
    })
    ->create();
```

**Important**: The `app()->booted()` callback is required because the IPs are loaded from cache, which may not be available during early bootstrap.

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
| `cloudflare:cache-info` | Display cache status (supports `--json` flag) |
| `cloudflare:clear` | Clear cache (`--current` or `--last-good` options) |

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
| `cache.store` | `null` | Cache store to use (null = default) |
| `cache.ttl` | 7 days | Cache duration in seconds |
| `cache.allow_stale` | `true` | Fall back to last-known-good IPs |
| `fallback.ipv4` | `[]` | Static fallback IPv4 ranges |
| `fallback.ipv6` | `[]` | Static fallback IPv6 ranges |
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

### Client IP still shows Cloudflare IP

1. Ensure the `app()->booted()` wrapper is used in `bootstrap/app.php`
2. Check that `X-Forwarded-For` header is being sent (use diagnostics endpoint)
3. Verify the request is actually coming through Cloudflare

### First deployment with empty cache

Add static fallback IPs in `config/laravel-cloudflare.php`:

```php
'fallback' => [
    'ipv4' => [
        '173.245.48.0/20',
        '103.21.244.0/22',
        // ... add current Cloudflare IPv4 ranges
    ],
    'ipv6' => [
        '2400:cb00::/32',
        '2606:4700::/32',
        // ... add current Cloudflare IPv6 ranges
    ],
],
```

These are used if the cache is empty (e.g., fresh deployment before first refresh).
