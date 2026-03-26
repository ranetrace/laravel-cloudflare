<?php

namespace Ranetrace\LaravelCloudflare\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Ranetrace\LaravelCloudflare\LaravelCloudflare
 *
 * @method static array all()
 * @method static array ipv4()
 * @method static array ipv6()
 * @method static bool refresh()
 * @method static array cacheInfo()
 */
class LaravelCloudflare extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ranetrace\LaravelCloudflare\LaravelCloudflare::class;
    }
}
