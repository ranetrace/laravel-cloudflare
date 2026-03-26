<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Ranetrace\LaravelCloudflare\LaravelCloudflare;

it('fetches and caches ipv4 and ipv6 lists via explicit refresh', function (): void {
    Http::fake([
        'www.cloudflare.com/ips-v4' => Http::response("1.1.1.1/32\n# comment\n\n10.0.0.0/8", 200),
        'www.cloudflare.com/ips-v6' => Http::response("2606:4700::/32\n# comment", 200),
    ]);

    Config::set('laravel-cloudflare.cache.ttl', 60);

    $service = app(LaravelCloudflare::class);
    $service->refresh();

    expect($service->ipv4())->toEqual(['1.1.1.1/32', '10.0.0.0/8']);
    expect($service->ipv6())->toEqual(['2606:4700::/32']);
    expect($service->all())->toEqual(['1.1.1.1/32', '10.0.0.0/8', '2606:4700::/32']);

    Http::preventStrayRequests();
    expect($service->all())->toEqual(['1.1.1.1/32', '10.0.0.0/8', '2606:4700::/32']);
});

it('refresh uses fallback when fetch fails', function (): void {
    Http::fake([
        'www.cloudflare.com/ips-v4' => Http::response("1.1.1.1/32\n10.0.0.0/8", 200),
        'www.cloudflare.com/ips-v6' => Http::response('2606:4700::/32', 200),
    ]);

    $service = app(LaravelCloudflare::class);
    $service->refresh();

    expect($service->all())->toEqual(['1.1.1.1/32', '10.0.0.0/8', '2606:4700::/32']);

    // Now fail HTTP and call refresh; it should keep existing cached values
    Http::fake([
        'www.cloudflare.com/*' => Http::response('', 500),
    ]);

    $service->refresh();

    expect($service->all())->toEqual(['1.1.1.1/32', '10.0.0.0/8', '2606:4700::/32']);
});

it('uses config fallback when cache is empty', function (): void {
    Config::set('laravel-cloudflare.fallback.ipv4', ['173.245.48.0/20', '103.21.244.0/22']);
    Config::set('laravel-cloudflare.fallback.ipv6', ['2400:cb00::/32']);
    Config::set('laravel-cloudflare.cache.throw_on_empty', false);
    Config::set('laravel-cloudflare.logging.failed_fetch', false);

    $service = app(LaravelCloudflare::class);

    expect($service->ipv4())->toEqual(['173.245.48.0/20', '103.21.244.0/22']);
    expect($service->ipv6())->toEqual(['2400:cb00::/32']);
    expect($service->all())->toEqual(['173.245.48.0/20', '103.21.244.0/22', '2400:cb00::/32']);
});

it('prefers cache over config fallback', function (): void {
    Http::fake([
        'www.cloudflare.com/ips-v4' => Http::response('1.1.1.1/32', 200),
        'www.cloudflare.com/ips-v6' => Http::response('2606:4700::/32', 200),
    ]);

    Config::set('laravel-cloudflare.fallback.ipv4', ['173.245.48.0/20']);
    Config::set('laravel-cloudflare.fallback.ipv6', ['2400:cb00::/32']);

    $service = app(LaravelCloudflare::class);
    $service->refresh();

    // Should use cached values, not fallback
    expect($service->ipv4())->toEqual(['1.1.1.1/32']);
    expect($service->ipv6())->toEqual(['2606:4700::/32']);
});

it('shows fallback info in cacheInfo', function (): void {
    Config::set('laravel-cloudflare.fallback.ipv4', ['173.245.48.0/20', '103.21.244.0/22']);
    Config::set('laravel-cloudflare.fallback.ipv6', ['2400:cb00::/32']);

    $service = app(LaravelCloudflare::class);
    $info = $service->cacheInfo();

    expect($info)->toHaveKey('fallback');
    expect($info['fallback'])->toEqual([
        'ipv4_count' => 2,
        'ipv6_count' => 1,
    ]);
});
