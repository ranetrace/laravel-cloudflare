<?php

namespace Ranetrace\LaravelCloudflare\Exceptions;

use RuntimeException;

class EmptyCacheException extends RuntimeException
{
    public static function forType(string $type): self
    {
        return new self("No Cloudflare IP list available for {$type} (both current and last_good empty)");
    }
}
