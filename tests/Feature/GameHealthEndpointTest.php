<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Services\GameHealthCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use ReflectionMethod;
use Tests\TestCase;

class GameHealthEndpointTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_real_main_screen_probes_render_home_and_explore(): void
    {
        $user = User::factory()->create();
        Character::query()->create([
            'user_id' => $user->id,
            'name' => '稼働監視確認者',
            'icon_path' => '/images/chara/chara_001.webp',
        ]);
        $probe = new ReflectionMethod(GameHealthCheckService::class, 'probeMainScreen');
        $service = app(GameHealthCheckService::class);

        $probe->invoke($service, $user, 'home');
        $probe->invoke($service, $user, 'dungeon');

        $this->assertFalse(request()->attributes->has(GameHealthCheckService::REQUEST_ATTRIBUTE));
    }

    public function test_real_health_endpoint_is_green_when_a_probe_character_exists(): void
    {
        $user = User::factory()->create();
        Character::query()->create([
            'user_id' => $user->id,
            'name' => '稼働監視確認者',
            'icon_path' => '/images/chara/chara_001.webp',
        ]);

        $this->getJson('/system/health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(6, 'checks');
    }

    public function test_main_screen_probe_restores_the_callers_auth_and_navigation_session(): void
    {
        $originalUser = User::factory()->create();
        $originalCharacter = Character::query()->create([
            'user_id' => $originalUser->id,
            'name' => '閲覧中の冒険者',
            'icon_path' => '/images/chara/chara_001.webp',
        ]);
        $probeUser = User::factory()->create();
        Character::query()->create([
            'user_id' => $probeUser->id,
            'name' => '稼働監視用の冒険者',
            'icon_path' => '/images/chara/chara_002.webp',
        ]);
        $this->actingAs($originalUser);
        session()->put([
            'current_character_id' => $originalCharacter->id,
            'target_area_id' => 123,
            'target_area_purpose' => 'material_source',
        ]);
        $probe = new ReflectionMethod(GameHealthCheckService::class, 'probeMainScreen');

        $probe->invoke(app(GameHealthCheckService::class), $probeUser, 'dungeon');

        $this->assertSame($originalUser->id, Auth::id());
        $this->assertSame($originalCharacter->id, session('current_character_id'));
        $this->assertSame(123, session('target_area_id'));
        $this->assertSame('material_source', session('target_area_purpose'));
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
