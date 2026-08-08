# Changelog

All notable changes to `laravel-cloudflare` will be documented in this file.

## Unreleased

Release that takes the trusted proxy list off the boot path.

### Added

- **`TrustCloudflareProxies` middleware**, which reads the IP list while handling a request instead of while booting. Wire it with `$middleware->replace(TrustProxies::class, TrustCloudflareProxies::class)` in `bootstrap/app.php`.
- **`trust_proxies.additional` config**: the proxies the middleware trusts alongside Cloudflare's ranges (a local web server, a load balancer, an ingress). The middleware does not consult `trustProxies(at: ...)`.

### Changed

- **The documented wiring is no longer `app()->booted()` + `trustProxies(at: ...)`.** That recipe made a reachable cache store a condition of every boot, console included, and there is a boot without one in every checkout of an application: `composer install` runs `package:discover` before a `.env` exists, so `CACHE_STORE` falls back to `database` and every `php artisan` invocation dies on a database nobody has created yet. The old recipe still works wherever the cache is reachable at boot; replacing it removes the failure mode.

### Upgrading

Replace the `app()->booted()` block in `bootstrap/app.php` with:

```php
use Illuminate\Http\Middleware\TrustProxies;
use Ranetrace\LaravelCloudflare\Http\Middleware\TrustCloudflareProxies;

$middleware->replace(TrustProxies::class, TrustCloudflareProxies::class);
```

Extra proxies that were spread into the `trustProxies(at: ...)` array move to `trust_proxies.additional` in `config/laravel-cloudflare.php`.

## v3.0.0 - 2026-06-18

Hardening release that prevents the package from ever handing `trustProxies()` an empty list.

### Breaking changes

- **`last_good` now lives in a durable file store, not the cache.** Previously the last-known-good list was kept in the same cache store as `current` via `forever()`. A shared cache being flushed (e.g. another app running `cache:clear`, which is `FLUSHDB` on Redis and ignores key prefixes) wiped it, leaving every layer empty. `last_good` is now persisted as JSON under `storage/laravel-cloudflare/last_good.json` (configurable via `last_good.path` / `CLOUDFLARE_LAST_GOOD_PATH`), so it survives `cache:clear`/`FLUSHDB` and deploys when `storage/` is shared between releases.
- **Removed the `cache.keys.last_good` config block.** Last-good keys no longer live in the cache. Old cached `last_good` entries are harmless and will expire on their own; no migration is required.
- The lookup order is unchanged in spirit but now reads: `current` (cache) → `last_good` (durable store) → static fallback → `[]`.

### Added

- **Package-bundled static fallback** (`resources/cloudflare-ips.php`) used as a cold-start floor on a brand-new install before the first refresh. Resolution is published `config('laravel-cloudflare.fallback.{type}')` when non-empty, otherwise the bundled list — the published config now ships empty (`fallback.ipv4/ipv6 => []`) meaning "use the package defaults".
- **`cloudflare:bundle-fallback`** maintainer command that fetches the live endpoints and rewrites `resources/cloudflare-ips.php` (run from the package repo before tagging a release).
- **Log-on-use warning:** when the static fallback layer is actually served (current and last_good both empty), a throttled warning (default once/hour, throttled via the durable store) is emitted so a broken refresh pipeline becomes visible instead of silently serving stale data. Configurable via `logging.static_fallback` and `logging.static_fallback_throttle`.

### Changed

- `cloudflare:clear --last-good` now clears the durable store; `cloudflare:cache-info` reports durable `last_good` presence/count and its file location.
- A failed refresh (empty fetch) still aborts without touching `last_good`, so it can never blank the fallback.
