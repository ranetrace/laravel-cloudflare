<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ranetrace\LaravelCloudflare\LaravelCloudflare;

function fakeCloudflareEndpoints(): void
{
    Http::fake([
        'www.cloudflare.com/ips-v4' => Http::response("1.1.1.1/32\n10.0.0.0/8", 200),
        'www.cloudflare.com/ips-v6' => Http::response('2606:4700::/32', 200),
    ]);
}

// Acceptance criterion 1
it('serves last_good from the durable store after the volatile cache is flushed', function (): void {
    fakeCloudflareEndpoints();

    app(LaravelCloudflare::class)->refresh();

    // Simulate another app running cache:clear -> FLUSHDB on a shared store.
    Cache::store()->clear();

    // The read path must never hit the network.
    Http::preventStrayRequests();

    // Fresh instance to bypass per-request memoization.
    $service = new LaravelCloudflare;

    expect($service->ipv4())->toEqual(['1.1.1.1/32', '10.0.0.0/8'])
        ->and($service->ipv6())->toEqual(['2606:4700::/32'])
        ->and($service->all())->toEqual(['1.1.1.1/32', '10.0.0.0/8', '2606:4700::/32']);
});

// Acceptance criterion 2
it('returns the package-bundled fallback and warns when cache and durable store are empty', function (): void {
    Config::set('laravel-cloudflare.fallback.ipv4', []);
    Config::set('laravel-cloudflare.fallback.ipv6', []);

    Log::spy();

    $all = (new LaravelCloudflare)->all();

    expect($all)->not->toBeEmpty()
        ->and($all)->toContain('173.245.48.0/20')  // bundled IPv4
        ->and($all)->toContain('2606:4700::/32');   // bundled IPv6

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (...$args): bool => isset($args[0]) && is_string($args[0]) && str_contains($args[0], 'static fallback'))
        ->atLeast()->once();
});

// Acceptance criterion 3
it('prefers a non-empty published config fallback over the bundled list', function (): void {
    Config::set('laravel-cloudflare.fallback.ipv4', ['203.0.113.0/24']);
    Config::set('laravel-cloudflare.fallback.ipv6', ['2001:db8::/32']);
    Config::set('laravel-cloudflare.logging.static_fallback', false);

    $service = new LaravelCloudflare;

    expect($service->ipv4())->toEqual(['203.0.113.0/24'])
        ->and($service->ipv6())->toEqual(['2001:db8::/32']);
});

it('falls through to the bundled list when the published config fallback is empty', function (): void {
    Config::set('laravel-cloudflare.fallback.ipv4', []);
    Config::set('laravel-cloudflare.fallback.ipv6', []);
    Config::set('laravel-cloudflare.logging.static_fallback', false);

    $service = new LaravelCloudflare;

    expect($service->ipv4())->toContain('173.245.48.0/20')
        ->and($service->ipv6())->toContain('2606:4700::/32');
});

// Acceptance criterion 4
it('clears the durable last_good store via cloudflare:clear --last-good', function (): void {
    fakeCloudflareEndpoints();

    $service = app(LaravelCloudflare::class);
    $service->refresh();

    expect($service->durable->getList('all'))->not->toBeEmpty();

    Artisan::call('cloudflare:clear', ['--last-good' => true]);

    expect($service->durable->getList('all'))->toBe([]);
});

it('does not clear last_good when only --current is requested', function (): void {
    fakeCloudflareEndpoints();

    $service = app(LaravelCloudflare::class);
    $service->refresh();

    Artisan::call('cloudflare:clear', ['--current' => true]);

    expect($service->durable->getList('all'))->toEqual(['1.1.1.1/32', '10.0.0.0/8', '2606:4700::/32']);
});

it('reports durable last_good presence and count in cache-info --json', function (): void {
    fakeCloudflareEndpoints();

    Artisan::call('cloudflare:refresh');
    Artisan::call('cloudflare:cache-info', ['--json' => true]);

    $info = json_decode(Artisan::output(), true);

    expect($info['last_good']['driver'])->toBe('file')
        ->and($info['last_good']['v4'])->toEqual(['present' => true, 'count' => 2])
        ->and($info['last_good']['v6'])->toEqual(['present' => true, 'count' => 1])
        ->and($info['last_good']['all'])->toEqual(['present' => true, 'count' => 3]);
});

// Acceptance criterion 5
it('does not overwrite last_good when a refresh fetch returns empty', function (): void {
    // First call to each endpoint succeeds; the second returns an empty/failed response.
    Config::set('laravel-cloudflare.http.retry', [1, 0]);
    Http::fakeSequence('www.cloudflare.com/ips-v4')
        ->push("1.1.1.1/32\n10.0.0.0/8", 200)
        ->push('', 500);
    Http::fakeSequence('www.cloudflare.com/ips-v6')
        ->push('2606:4700::/32', 200)
        ->push('', 500);
    Config::set('laravel-cloudflare.logging.failed_fetch', false);

    $service = app(LaravelCloudflare::class);
    expect($service->refresh())->toBeTrue();

    // The next refresh fetches empty lists and must abort without touching last_good.
    expect($service->refresh())->toBeFalse();

    // Wipe the volatile cache so reads must come from durable last_good.
    Cache::store()->clear();

    $fresh = new LaravelCloudflare;
    expect($fresh->all())->toEqual(['1.1.1.1/32', '10.0.0.0/8', '2606:4700::/32']);
});

// Throttling of the "static fallback in use" warning (log-on-use)
it('throttles the static fallback warning within the configured window', function (): void {
    Config::set('laravel-cloudflare.fallback.ipv4', []);
    Config::set('laravel-cloudflare.fallback.ipv6', []);
    Config::set('laravel-cloudflare.logging.static_fallback_throttle', 3600);

    Log::spy();

    (new LaravelCloudflare)->all();
    (new LaravelCloudflare)->all();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (...$args): bool => isset($args[0]) && is_string($args[0]) && str_contains($args[0], 'static fallback'))
        ->once();
});

it('warns again once the static fallback throttle window passes', function (): void {
    Config::set('laravel-cloudflare.fallback.ipv4', []);
    Config::set('laravel-cloudflare.fallback.ipv6', []);
    Config::set('laravel-cloudflare.logging.static_fallback_throttle', 3600);

    Log::spy();

    (new LaravelCloudflare)->all();

    $this->travel(2)->hours();

    (new LaravelCloudflare)->all();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (...$args): bool => isset($args[0]) && is_string($args[0]) && str_contains($args[0], 'static fallback'))
        ->twice();
});
