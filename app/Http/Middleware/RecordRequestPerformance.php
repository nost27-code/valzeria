<?php

namespace App\Http\Middleware;

use App\Services\RequestPerformanceCollector;
use App\Services\RequestPerformanceStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RecordRequestPerformance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('request_performance.enabled') || $request->is('admin', 'admin/*', 'up', 'system/health')) {
            return $next($request);
        }
        $collector = app(RequestPerformanceCollector::class);
        try {
            $collector->begin();
        } catch (Throwable) {
            $collector->reset();

            return $next($request);
        }
        $status = 500;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } catch (Throwable $exception) {
            $collector->exception($exception);
            throw $exception;
        } finally {
            try {
                if ($profile = $collector->finish($request, $status)) {
                    app(RequestPerformanceStore::class)->write($profile);
                }
            } catch (Throwable) {
                $collector->reset();
            }
        }
    }
}
