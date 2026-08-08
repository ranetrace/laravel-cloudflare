<?php

namespace Ranetrace\LaravelCloudflare\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Config;
use Ranetrace\LaravelCloudflare\LaravelCloudflare;

/**
 * Trust Cloudflare's ranges, read while handling a request instead of while
 * booting the application.
 *
 * Register it in `bootstrap/app.php` in place of the framework's middleware:
 *
 * ```php
 * $middleware->replace(
 *     Illuminate\Http\Middleware\TrustProxies::class,
 *     Ranetrace\LaravelCloudflare\Http\Middleware\TrustCloudflareProxies::class,
 * );
 * ```
 *
 * This is the supported alternative to handing `trustProxies(at: ...)` a list
 * resolved inside `app()->booted()`. That recipe makes a reachable cache store
 * a condition of every boot, console included, and there is a boot without one
 * in every checkout of an application: `composer install` runs
 * `package:discover` before a `.env` exists, so `CACHE_STORE` falls back to
 * `database` and the read hits a database nobody has created yet. Proxy trust
 * is a property of an HTTP request, so it is resolved while answering one.
 *
 * Because `proxies()` replaces the parent's lookup, `trustProxies(at: ...)` is
 * not consulted. Proxies to trust besides Cloudflare go in
 * `laravel-cloudflare.trust_proxies.additional`.
 */
class TrustCloudflareProxies extends TrustProxies
{
    /**
     * @return array<int, string>
     */
    protected function proxies(): array
    {
        return [
            ...app(LaravelCloudflare::class)->all(),
            ...$this->additionalProxies(),
        ];
    }

    /**
     * The hops in front of the application that are not Cloudflare: a local
     * web server, a load balancer, an ingress.
     *
     * @return array<int, string>
     */
    protected function additionalProxies(): array
    {
        $additional = Config::get('laravel-cloudflare.trust_proxies.additional', []);

        if (! is_array($additional)) {
            return [];
        }

        return array_values(array_filter($additional, 'is_string'));
    }
}
