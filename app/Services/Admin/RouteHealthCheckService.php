<?php

namespace App\Services\Admin;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RouteHealthCheckService
{
    private const TIMEOUT_SECONDS = 8;

    private const EXCLUDED_URIS = [
        'admin/contact-messages/badge-count', 'auth/google', 'auth/google/callback', 'auth/logout', 'auth/mock-login',
    ];

    public function routes(): array
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => in_array('GET', $route->methods(), true))
            ->map(fn (Route $route): array => $this->routeDefinition($route))
            ->sortBy('uri')->values()->all();
    }

    public function check(): array
    {
        $results = [];
        foreach ($this->routes() as $route) {
            if ($route['excluded']) {
                $results[] = $route + ['status' => null, 'milliseconds' => null, 'state' => 'excluded'];
                continue;
            }
            $startedAt = microtime(true);
            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)->withoutRedirecting()
                    ->withHeaders(['X-Valzeria-Health-Check' => '1'])->get($route['url']);
                $status = $response->status();
                $results[] = $route + [
                    'status' => $status,
                    'milliseconds' => (int) round((microtime(true) - $startedAt) * 1000),
                    'state' => $status >= 500 ? 'failed' : ($status >= 400 ? 'warning' : 'ok'),
                ];
            } catch (\Throwable $e) {
                report($e);
                $results[] = $route + [
                    'status' => null, 'milliseconds' => (int) round((microtime(true) - $startedAt) * 1000),
                    'state' => 'failed', 'error' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    private function routeDefinition(Route $route): array
    {
        $uri = ltrim($route->uri(), '/');
        $hasRequiredParameter = Str::contains($uri, '{');
        $excluded = $hasRequiredParameter || in_array($uri, self::EXCLUDED_URIS, true)
            || Str::startsWith($uri, ['dev/', 'run-', 'debug-']);
        return [
            'name' => $route->getName() ?: '（名称なし）', 'uri' => '/' . $uri, 'url' => url($uri),
            'excluded' => $excluded,
            'reason' => $hasRequiredParameter ? 'URLパラメータが必要なため対象外' : ($excluded ? '副作用または開発用URLのため対象外' : null),
        ];
    }
}
