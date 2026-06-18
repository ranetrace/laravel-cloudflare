<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

it('regenerates the bundled fallback file from the live endpoints', function (): void {
    Http::fake([
        'www.cloudflare.com/ips-v4' => Http::response("9.9.9.0/24\n8.8.8.0/24", 200),
        'www.cloudflare.com/ips-v6' => Http::response('2001:db8::/32', 200),
    ]);

    $path = sys_get_temp_dir().'/laravel-cloudflare-tests/bundle_'.uniqid('', true).'.php';

    $exit = Artisan::call('cloudflare:bundle-fallback', ['--path' => $path]);

    expect($exit)->toBe(0)
        ->and(is_file($path))->toBeTrue();

    $data = require $path;

    expect($data['ipv4'])->toEqual(['9.9.9.0/24', '8.8.8.0/24'])
        ->and($data['ipv6'])->toEqual(['2001:db8::/32']);

    @unlink($path);
});

it('refuses to write an empty bundled fallback', function (): void {
    Http::fake([
        'www.cloudflare.com/*' => Http::response('', 500),
    ]);

    Config::set('laravel-cloudflare.logging.failed_fetch', false);

    $path = sys_get_temp_dir().'/laravel-cloudflare-tests/bundle_'.uniqid('', true).'.php';

    $exit = Artisan::call('cloudflare:bundle-fallback', ['--path' => $path]);

    expect($exit)->toBe(1)
        ->and(is_file($path))->toBeFalse();
});
