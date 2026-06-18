<?php

namespace Ranetrace\LaravelCloudflare\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Ranetrace\LaravelCloudflare\LaravelCloudflare
 *
 * @method static array<int, string> all()
 * @method static array<int, string> ipv4()
 * @method static array<int, string> ipv6()
 * @method static bool refresh()
 * @method static array<string, mixed> cacheInfo()
 */
class LaravelCloudflare extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ranetrace\LaravelCloudflare\LaravelCloudflare::class;
    }
}
