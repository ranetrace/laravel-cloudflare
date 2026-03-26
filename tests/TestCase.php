<?php

namespace Ranetrace\LaravelCloudflare\Tests;

use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase as Orchestra;
use Ranetrace\LaravelCloudflare\LaravelCloudflareServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LaravelCloudflareServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Use array cache to avoid external dependencies
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }
}
