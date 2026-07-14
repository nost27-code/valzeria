<?php

namespace Tests\Feature;

use App\Services\GameHealthCheckService;
use Tests\TestCase;

class GameHealthEndpointTest extends TestCase
{
    public function test_it_returns_green_only_when_every_probe_is_healthy(): void
    {
        $this->app->instance(GameHealthCheckService::class, $this->healthCheck(true));

        $response = $this->getJson('/system/health');

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(6, 'checks');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_it_returns_service_unavailable_when_any_probe_is_unhealthy(): void
    {
        $this->app->instance(GameHealthCheckService::class, $this->healthCheck(false));

        $this->getJson('/system/health')
            ->assertStatus(503)
            ->assertJsonPath('ok', false);
    }

    private function healthCheck(bool $ok): GameHealthCheckService
    {
        $service = $this->createMock(GameHealthCheckService::class);
        $service->method('check')->willReturn([
            'ok' => $ok,
            'checked_at' => '2026-07-14T00:00:00+09:00',
            'checks' => [
                ['key' => 'core', 'label' => 'ゲーム本体', 'ok' => $ok, 'milliseconds' => 1],
                ['key' => 'home', 'label' => 'ホーム', 'ok' => $ok, 'milliseconds' => 1],
                ['key' => 'explore', 'label' => '探索', 'ok' => $ok, 'milliseconds' => 1],
                ['key' => 'equipment', 'label' => '装備', 'ok' => $ok, 'milliseconds' => 1],
                ['key' => 'inventory', 'label' => '持ち物', 'ok' => $ok, 'milliseconds' => 1],
                ['key' => 'market', 'label' => '市場', 'ok' => $ok, 'milliseconds' => 1],
            ],
        ]);

        return $service;
    }
}
