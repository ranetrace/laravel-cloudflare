<?php

use Illuminate\Support\Facades\Route;
use Ranetrace\LaravelCloudflare\LaravelCloudflareServiceProvider;

it('does not register the diagnostics route by default', function (): void {
    // Ensure default is disabled
    expect(config('laravel-cloudflare.diagnostics.enabled'))->toBeFalse();

    // Routes should not include our named route
    $route = Route::getRoutes()->getByName('laravel-cloudflare.diagnostics');
    expect($route)->toBeNull();
});

it('registers diagnostics route when enabled', function (): void {
    config()->set('laravel-cloudflare.diagnostics.enabled', true);

    // Manually boot the provider again to apply conditional routes with updated config
    (new LaravelCloudflareServiceProvider(app()))->boot();

    $response = $this->get('/cloudflare-diagnose', [
        'X-Forwarded-For' => '203.0.113.5',
        'CF-Connecting-IP' => '198.51.100.7',
        'True-Client-IP' => '192.0.2.9',
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'laravel_ip',
        'remote_addr',
        'x_forwarded_for',
        'cf_connecting_ip',
        'true_client_ip',
        'server_https',
        'is_secure',
    ]);
});

it('registers diagnostics route at custom path', function (): void {
    config()->set('laravel-cloudflare.diagnostics.enabled', true);
    config()->set('laravel-cloudflare.diagnostics.path', '/diag');

    // Manually boot the provider again to apply conditional routes with updated config
    (new LaravelCloudflareServiceProvider(app()))->boot();

    $response = $this->get('/diag');
    $response->assertOk();
});
