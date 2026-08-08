## Laravel Cloudflare

This package manages Cloudflare IP ranges for Laravel's TrustProxies middleware.

Use the `laravel-cloudflare-setup` skill for installation and configuration guidance.

### Wiring (IMPORTANT)

Trust the ranges by replacing the framework's middleware in `bootstrap/app.php`:

```php
$middleware->replace(
    Illuminate\Http\Middleware\TrustProxies::class,
    Ranetrace\LaravelCloudflare\Http\Middleware\TrustCloudflareProxies::class,
);
```

Never resolve the list at boot — not via `app()->booted()` + `trustProxies(at: ...)` (which older docs of this package recommended), and not anywhere else in `bootstrap/app.php`. The list comes from the cache, and a boot that reads the cache fails wherever the cache is not reachable yet: `composer install` runs `package:discover` before a `.env` exists, so `CACHE_STORE` falls back to `database` and every `php artisan` invocation dies on a database nobody has created. Proxies to trust besides Cloudflare go in `laravel-cloudflare.trust_proxies.additional`.

### Reading the list

`all()`, `ipv4()` and `ipv6()` already answer through four layers: the cache, the durable `last_good` file, the package-bundled ranges, then `[]`. A cache that is empty, or a store that cannot be read at all, falls through them with a throttled warning rather than throwing.

So do not write defensive code around these calls: no `try`/`catch`, no "is the cache warm" check first, no second fallback list of your own. `refresh()` and `cacheInfo()` are the two that do throw when the store is broken, which is what their callers want to hear.

Quick reference:
- `php artisan cloudflare:refresh` - Fetch and cache IPs
- `php artisan cloudflare:cache-info` - View cache status
- `php artisan cloudflare:clear` - Clear cached IPs
