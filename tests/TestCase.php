<?php

namespace Ranetrace\LaravelCloudflare\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Ranetrace\LaravelCloudflare\LaravelCloudflareServiceProvider;

class TestCase extends Orchestra
{
    protected string $durableStorePath;

    protected function setUp(): void
    {
        // Unique per-test path so the durable last_good file never leaks state
        // between tests sharing a process.
        $this->durableStorePath = sys_get_temp_dir()
            .'/laravel-cloudflare-tests/last_good_'.uniqid('', true).'.json';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if (isset($this->durableStorePath) && is_file($this->durableStorePath)) {
            @unlink($this->durableStorePath);
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [LaravelCloudflareServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Use array cache to avoid external dependencies
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('laravel-cloudflare.last_good.path', $this->durableStorePath);
    }
}
