<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\Enemy;
use App\Models\User;
use App\Services\BattleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 敵耐久補正がBattleServiceの実戦（executeBattle）と画面表示（enemyStatDisplay）で
 * 常に同じ値を参照することを確認する統合テスト。
 */
class EnemyDurabilityBattleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_and_actual_battle_use_the_same_durability_multiplied_stats(): void
    {
        $area = Area::query()->create([
            'name' => '都市8試験エリア', 'slug' => 'test-city8-area-' . uniqid(),
            'city_id' => 8, 'recommended_level_min' => 100, 'recommended_level_max' => 110,
        ]);
        $enemy = Enemy::query()->create([
            'name' => '試験強敵', 'area_id' => $area->id, 'level' => 107,
            'max_hp' => 10000, 'str' => 700, 'def' => 300, 'agi' => 300, 'mag' => 400, 'spr' => 250, 'luk' => 10,
            'is_boss' => false, 'role_key' => 'strong', 'exp_reward' => 1, 'gold_reward' => 1,
        ]);
        $character = $this->character(2000);

        $result = app(BattleService::class)->executeBattle($character, $enemy);

        // city8 strong: hp=1.08, def_spr=1.10, atk_mag=1.00 (config/enemy_durability.php)
        $this->assertSame(1.08, $result->enemyStatDisplay['durability']['hp_multiplier']);
        $this->assertSame(1.10, $result->enemyStatDisplay['durability']['def_spr_multiplier']);
        $this->assertSame(1.00, $result->enemyStatDisplay['durability']['atk_mag_multiplier']);
        $this->assertSame('city8', $result->enemyStatDisplay['durability']['tier']);

        // 危険度ボーナスが存在しない(探索状態なし)ため、表示上のbaseは
        // 耐久補正だけを適用した値と完全一致する = 表示と実戦の乖離がないことの直接証拠。
        $this->assertSame((int) round(300 * 1.10), $result->enemyStatDisplay['def']['base']);
        $this->assertSame(0, $result->enemyStatDisplay['def']['bonus']);
        $this->assertSame((int) round(10000 * 1.08), $result->enemyStatDisplay['hp']['base']);
    }

    public function test_city1_enemy_is_completely_unaffected_by_durability_config(): void
    {
        $area = Area::query()->create([
            'name' => '都市1試験エリア', 'slug' => 'test-city1-area-' . uniqid(),
            'city_id' => 1, 'recommended_level_min' => 1, 'recommended_level_max' => 10,
        ]);
        $enemy = Enemy::query()->create([
            'name' => '試験ボス', 'area_id' => $area->id, 'level' => 9,
            'max_hp' => 500, 'str' => 60, 'def' => 25, 'agi' => 20, 'mag' => 15, 'spr' => 15, 'luk' => 10,
            'is_boss' => true, 'role_key' => 'boss', 'exp_reward' => 1, 'gold_reward' => 1,
        ]);
        $character = $this->character(100);

        $result = app(BattleService::class)->executeBattle($character, $enemy);

        $this->assertSame(1.0, $result->enemyStatDisplay['durability']['hp_multiplier']);
        $this->assertSame(1.0, $result->enemyStatDisplay['durability']['def_spr_multiplier']);
        // 都市1〜3は物理/魔法0.92緩和が別途かかるため、defは補正なし・strのみ0.92倍される
        $this->assertSame(25, $result->enemyStatDisplay['def']['base']);
    }

    public function test_region_depth_enemy_uses_sandra_entry_baseline_before_danger_scaling(): void
    {
        $area = Area::query()->create([
            'name' => '黒炉深坑試験エリア', 'slug' => 'test-region-depth-area-' . uniqid(),
            'city_id' => 4, 'recommended_level_min' => 56, 'recommended_level_max' => 57,
        ]);
        $enemy = Enemy::query()->create([
            'name' => '黒炉試験敵', 'area_id' => $area->id, 'level' => 56,
            'max_hp' => 100, 'str' => 100, 'def' => 100, 'agi' => 100, 'mag' => 100, 'spr' => 100, 'luk' => 100,
            'is_boss' => false, 'role_key' => 'normal', 'exp_reward' => 1, 'gold_reward' => 1,
        ]);
        $enemy->setAttribute('region_depth_dungeon_key', 'granberg_black_furnace');
        $enemy->setAttribute('region_depth_danger_rate', 0);

        $result = app(BattleService::class)->executeBattle($this->character(2000), $enemy);

        $this->assertSame(142, $result->enemyStatDisplay['hp']['base']);
        $this->assertSame(139, $result->enemyStatDisplay['str']['base']);
        $this->assertSame(138, $result->enemyStatDisplay['def']['base']);
        $this->assertSame(0, $result->enemyStatDisplay['hp']['bonus']);
    }

    public function test_super_boss_multiplier_overrides_its_home_city_tier_in_actual_battle(): void
    {
        $area = Area::query()->create([
            'name' => '隠し超ボスエリア', 'slug' => 'test-superboss-area-' . uniqid(),
            'city_id' => 9, 'recommended_level_min' => 200, 'recommended_level_max' => 240,
        ]);
        $enemy = Enemy::query()->create([
            'name' => '試験超ボス', 'area_id' => $area->id, 'level' => 240,
            'max_hp' => 100000, 'str' => 4800, 'def' => 2300, 'agi' => 1100, 'mag' => 4600, 'spr' => 1300, 'luk' => 20,
            'is_boss' => true, 'role_key' => 'boss', 'exp_reward' => 1, 'gold_reward' => 1,
        ]);
        $character = $this->character(8000);

        $result = app(BattleService::class)->executeBattle($character, $enemy);

        // super_boss: hp=1.10, def_spr=1.15 (city9のボス倍率1.10/1.20ではない)
        $this->assertSame('super_boss', $result->enemyStatDisplay['durability']['tier']);
        $this->assertSame(1.10, $result->enemyStatDisplay['durability']['hp_multiplier']);
        $this->assertSame(1.15, $result->enemyStatDisplay['durability']['def_spr_multiplier']);
    }

    private function character(int $attackBase): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => '耐久テスト冒険者',
            'explore_stamina' => 0,
            'hp_base' => 5000, 'mp_base' => 100,
            'attack_base' => $attackBase, 'defense_base' => (int) ($attackBase * 0.8),
            'speed_base' => (int) ($attackBase * 0.8), 'magic_base' => 0,
            'spirit_base' => (int) ($attackBase * 0.7), 'luck_base' => (int) ($attackBase * 0.5),
            'current_hp' => 5000, 'current_mp' => 100,
        ]);
    }
}
