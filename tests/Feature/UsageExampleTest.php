<?php

use Illuminate\Support\Facades\Http;
use Ranetrace\LaravelCloudflare\LaravelCloudflare;

it('can provide an array suitable for trust proxies', function (): void {
    Http::fake([
        'www.cloudflare.com/ips-v4' => Http::response("1.1.1.1/32\n10.0.0.0/8", 200),
        'www.cloudflare.com/ips-v6' => Http::response('2606:4700::/32', 200),
    ]);

    $service = app(LaravelCloudflare::class);
    $service->refresh();
    $ips = $service->all();

    expect($ips)->toBeArray()
        ->and($ips)->toEqual(['1.1.1.1/32', '10.0.0.0/8', '2606:4700::/32']);
});
