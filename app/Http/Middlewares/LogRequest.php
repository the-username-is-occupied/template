<?php

declare(strict_types=1);

namespace App\Http\Middlewares;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogRequest
{
    private const array HIDDEN_FIELDS = [
        'password',
        'password_confirmation',
        'token',
        'secret',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('request_start_time', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $startTime = $request->attributes->get('request_start_time', microtime(true));
        $route = $request->route();

        Log::channel('requests')->info('Request', [
            'route_name' => $route?->getName(),
            'method' => $request->method(),
            'uri' => $request->getPathInfo(),
            'status' => $response->getStatusCode(),
            'duration' => format_duration(microtime(true) - $startTime),
            'memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2).' MB',
            'ip' => $request->ip(),
            'payload' => $request->except(self::HIDDEN_FIELDS),
        ]);
    }
}
