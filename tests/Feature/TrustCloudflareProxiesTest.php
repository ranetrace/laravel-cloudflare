<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Ranetrace\LaravelCloudflare\Http\Middleware\TrustCloudflareProxies;

/**
 * 173.245.48.10 sits in 173.245.48.0/20, a range the package ships in its
 * bundled fallback, so these hold with an empty cache and without network:
 * LaravelCloudflare::all() always has it to serve.
 */
beforeEach(function (): void {
    Http::preventStrayRequests();

    Route::get('/proxy-probe', fn (): string => request()->ip())
        ->middleware(TrustCloudflareProxies::class);
});

it('resolves the visitor ip for a request arriving through cloudflare', function (): void {
    $response = $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.10'])
        ->get('/proxy-probe', ['X-Forwarded-For' => '203.0.113.7']);

    expect($response->content())->toBe('203.0.113.7');
});

it('ignores a forwarded header from an address that is not cloudflare', function (): void {
    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])
        ->get('/proxy-probe', ['X-Forwarded-For' => '203.0.113.7']);

    expect($response->content())->toBe('198.51.100.4');
});

it('also trusts the proxies configured beside cloudflare', function (): void {
    Config::set('laravel-cloudflare.trust_proxies.additional', ['198.51.100.4']);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])
        ->get('/proxy-probe', ['X-Forwarded-For' => '203.0.113.7']);

    expect($response->content())->toBe('203.0.113.7');
});
