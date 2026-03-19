<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequestTimingLogger
{
    public function handle(Request $request, Closure $next)
    {
        if (! (bool) env('REQUEST_TIMING_LOG_ENABLED', false)) {
            return $next($request);
        }

        $start = microtime(true);

        $response = $next($request);

        $durationMs = (microtime(true) - $start) * 1000;
        $thresholdMs = (float) env('REQUEST_TIMING_LOG_THRESHOLD_MS', 200);

        // Log moderately slow requests to surface bottlenecks.
        if ($durationMs >= $thresholdMs) {
            $userId = $request->user() ? $request->user()->id : '-';
            $line = sprintf(
                "[%s] %s %s %d %.2fms user=%s\n",
                date('Y-m-d H:i:s'),
                $request->getMethod(),
                $request->getRequestUri(),
                method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 0,
                $durationMs,
                $userId
            );

            $logPath = storage_path('logs/royal-timing.log');
            file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
        }

        return $response;
    }
}
