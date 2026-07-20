<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Services\PublicLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicLogServiceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_region_depth_record_is_published_for_an_admin_character_only_as_an_explicit_exception(): void
    {
        $character = $this->adminCharacter();
        $service = app(PublicLogService::class);

        $service->addLog('region_depth_dungeon', '【黒炉深坑・終炉到達】記録テストさんが危険度770%へ到達しました！', $character, 2);
        $service->addLog('area', '【解放】記録テストさんが新たな領域を発見しました！', $character, 2);

        $this->assertDatabaseHas('public_logs', [
            'type' => 'region_depth_dungeon',
            'character_id' => $character->id,
        ]);
        $this->assertDatabaseMissing('public_logs', [
            'type' => 'area',
            'character_id' => $character->id,
        ]);
    }

    private function adminCharacter(): Character
    {
        $user = User::factory()->create(['role' => 'admin']);

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => '記録テスト',
            'hp_base' => 100,
            'mp_base' => 10,
            'attack_base' => 10,
            'defense_base' => 10,
            'speed_base' => 10,
            'magic_base' => 10,
            'spirit_base' => 10,
            'luck_base' => 10,
            'current_hp' => 100,
            'current_mp' => 10,
        ]);
    }
}
