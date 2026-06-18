<?php

namespace Ranetrace\LaravelCloudflare;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Ranetrace\LaravelCloudflare\Commands\CloudflareBundleFallbackCommand;
use Ranetrace\LaravelCloudflare\Commands\CloudflareCacheInfoCommand;
use Ranetrace\LaravelCloudflare\Commands\CloudflareClearCommand;
use Ranetrace\LaravelCloudflare\Commands\CloudflareRefreshCommand;
use Ranetrace\LaravelCloudflare\Http\Controllers\CloudflareDiagnosticsController;

class LaravelCloudflareServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-cloudflare.php', 'laravel-cloudflare');

        // Bind the main service
        $this->app->singleton(LaravelCloudflare::class, static function (): LaravelCloudflare {
            return new LaravelCloudflare;
        });
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/laravel-cloudflare.php' => config_path('laravel-cloudflare.php'),
        ], 'laravel-cloudflare');

        // Register the command
        if ($this->app->runningInConsole()) {
            $this->commands([
                CloudflareRefreshCommand::class,
                CloudflareCacheInfoCommand::class,
                CloudflareClearCommand::class,
                CloudflareBundleFallbackCommand::class,
            ]);
        }

        // Optionally register diagnostics route
        $config = (array) config('laravel-cloudflare.diagnostics', []);
        if (($config['enabled'] ?? false) === true) {
            $path = (string) ($config['path'] ?? '/cloudflare-diagnose');
            $middleware = $config['middleware'] ?? ['web'];

            Route::middleware($middleware)
                ->get($path, CloudflareDiagnosticsController::class)
                ->name('laravel-cloudflare.diagnostics');
        }
    }
}
