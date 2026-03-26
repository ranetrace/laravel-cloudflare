<?php

namespace Ranetrace\LaravelCloudflare\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CloudflareDiagnosticsController
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'laravel_ip' => $request->ip(),
            'remote_addr' => $request->server('REMOTE_ADDR'),
            'x_forwarded_for' => $request->header('X-Forwarded-For'),
            'cf_connecting_ip' => $request->header('CF-Connecting-IP'),
            'true_client_ip' => $request->header('True-Client-IP'),
            'server_https' => $request->server('HTTPS'),
            'is_secure' => $request->isSecure(),
        ]);
    }
}
