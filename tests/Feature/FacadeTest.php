<?php

use Illuminate\Support\Facades\Http;
use Ranetrace\LaravelCloudflare\Facades\LaravelCloudflare as LaravelCloudflareFacade;
use Ranetrace\LaravelCloudflare\LaravelCloudflare;

it('facade proxies to the service', function (): void {
    Http::fake([
        'www.cloudflare.com/ips-v4' => Http::response("1.1.1.1/32\n10.0.0.0/8", 200),
        'www.cloudflare.com/ips-v6' => Http::response('2606:4700::/32', 200),
    ]);

    app(LaravelCloudflare::class)->refresh();
    $ips = LaravelCloudflareFacade::all();

    expect($ips)->toEqual(['1.1.1.1/32', '10.0.0.0/8', '2606:4700::/32']);
});
