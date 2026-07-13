<?php

namespace Tests\Unit;

use App\Models\Enemy;
use App\Services\EnemyDurabilityService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class EnemyDurabilityServiceTest extends TestCase
{
    public function test_city1_to_7_are_completely_unaffected(): void
    {
        $service = new EnemyDurabilityService();

        foreach ([1, 2, 3, 4, 5, 6, 7] as $cityId) {
            $enemy = new Enemy(['id' => 1000 + $cityId, 'role_key' => 'boss', 'is_boss' => true, 'level' => 50]);
            $mul = $service->multiplierFor($enemy, $cityId);

            $this->assertSame(1.0, $mul['hp'], "city{$cityId}");
            $this->assertSame(1.0, $mul['def_spr'], "city{$cityId}");
            $this->assertSame(1.0, $mul['atk_mag'], "city{$cityId}");
        }
    }

    public function test_excluded_roles_are_never_multiplied_even_in_city8(): void
    {
        $service = new EnemyDurabilityService();

        foreach (['normal_weak', 'deep_candidate', 'golden'] as $role) {
            $enemy = new Enemy(['id' => 5000, 'role_key' => $role, 'is_boss' => false, 'level' => 100]);
            $mul = $service->multiplierFor($enemy, 8);

            $this->assertSame(1.0, $mul['hp'], $role);
            $this->assertSame(1.0, $mul['def_spr'], $role);
            $this->assertSame(1.0, $mul['atk_mag'], $role);
        }
    }

    public function test_safe_enemy_id_is_excluded_regardless_of_role(): void
    {
        Config::set('enemy_durability.safe_enemy_ids', [409]);
        $service = new EnemyDurabilityService();

        $enemy = new Enemy(['id' => 409, 'role_key' => 'normal', 'is_boss' => false, 'level' => 138]);
        $mul = $service->multiplierFor($enemy, 10);

        $this->assertSame(1.0, $mul['hp']);
        $this->assertSame(1.0, $mul['def_spr']);
    }

    public function test_city8_role_multipliers_match_final_spec(): void
    {
        $service = new EnemyDurabilityService();
        $cases = [
            'normal' => [1.08, 1.00, 1.00],
            'rare' => [1.05, 1.00, 1.00],
            'strong' => [1.08, 1.10, 1.00],
            'boss' => [1.08, 1.15, 1.00],
        ];

        foreach ($cases as $role => [$hp, $defSpr, $atkMag]) {
            $enemy = new Enemy(['id' => 6000, 'role_key' => $role, 'is_boss' => $role === 'boss', 'level' => 110]);
            $mul = $service->multiplierFor($enemy, 8);

            $this->assertSame($hp, $mul['hp'], "city8 {$role} hp");
            $this->assertSame($defSpr, $mul['def_spr'], "city8 {$role} def_spr");
            $this->assertSame($atkMag, $mul['atk_mag'], "city8 {$role} atk_mag");
        }
    }

    public function test_city9_role_multipliers_match_final_spec(): void
    {
        $service = new EnemyDurabilityService();
        $cases = [
            'normal' => [1.10, 1.05, 1.00],
            'rare' => [1.08, 1.05, 1.00],
            'strong' => [1.10, 1.15, 1.00],
            'boss' => [1.10, 1.20, 1.05],
        ];

        foreach ($cases as $role => [$hp, $defSpr, $atkMag]) {
            $enemy = new Enemy(['id' => 7000, 'role_key' => $role, 'is_boss' => $role === 'boss', 'level' => 120]);
            $mul = $service->multiplierFor($enemy, 9);

            $this->assertSame($hp, $mul['hp'], "city9 {$role} hp");
            $this->assertSame($defSpr, $mul['def_spr'], "city9 {$role} def_spr");
            $this->assertSame($atkMag, $mul['atk_mag'], "city9 {$role} atk_mag");
        }
    }

    public function test_city10_role_multipliers_match_final_spec(): void
    {
        $service = new EnemyDurabilityService();
        $cases = [
            'normal' => [1.12, 1.08, 1.00],
            'rare' => [1.10, 1.08, 1.00],
            'strong' => [1.10, 1.20, 1.00],
            'boss' => [1.12, 1.25, 1.05],
        ];

        foreach ($cases as $role => [$hp, $defSpr, $atkMag]) {
            $enemy = new Enemy(['id' => 8000, 'role_key' => $role, 'is_boss' => $role === 'boss', 'level' => 135]);
            $mul = $service->multiplierFor($enemy, 10);

            $this->assertSame($hp, $mul['hp'], "city10 {$role} hp");
            $this->assertSame($defSpr, $mul['def_spr'], "city10 {$role} def_spr");
            $this->assertSame($atkMag, $mul['atk_mag'], "city10 {$role} atk_mag");
        }
    }

    public function test_hikyo_normal_rare_strong_multipliers_match_final_spec(): void
    {
        $service = new EnemyDurabilityService();
        $cases = [
            'normal' => [1.12, 1.10, 1.00],
            'rare' => [1.12, 1.10, 1.00],
            'strong' => [1.15, 1.25, 1.00],
        ];

        foreach ([101, 102, 103] as $cityId) {
            foreach ($cases as $role => [$hp, $defSpr, $atkMag]) {
                $enemy = new Enemy(['id' => 9000 + $cityId, 'role_key' => $role, 'is_boss' => false, 'level' => 150]);
                $mul = $service->multiplierFor($enemy, $cityId);

                $this->assertSame($hp, $mul['hp'], "hikyo{$cityId} {$role} hp");
                $this->assertSame($defSpr, $mul['def_spr'], "hikyo{$cityId} {$role} def_spr");
                $this->assertSame($atkMag, $mul['atk_mag'], "hikyo{$cityId} {$role} atk_mag");
            }
        }
    }

    public function test_hikyo_boss_uses_the_revised_gentler_multipliers(): void
    {
        $service = new EnemyDurabilityService();
        $enemy = new Enemy(['id' => 9500, 'role_key' => 'boss', 'is_boss' => true, 'level' => 168]);
        $mul = $service->multiplierFor($enemy, 103);

        $this->assertSame(1.10, $mul['hp']);
        $this->assertSame(1.20, $mul['def_spr']);
        $this->assertSame(1.05, $mul['atk_mag']);
    }

    public function test_super_boss_uses_lower_multipliers_than_hikyo_boss_and_overrides_city_tier(): void
    {
        Config::set('enemy_durability.super_boss_level_threshold', 200);
        $service = new EnemyDurabilityService();

        // city9所属だが Lv240 の隠し超ボス(竜王バハムート相当)は city9 ボス倍率ではなく super_boss 倍率を使う
        $enemy = new Enemy(['id' => 423, 'role_key' => 'boss', 'is_boss' => true, 'level' => 240]);
        $mul = $service->multiplierFor($enemy, 9);

        $this->assertSame(1.10, $mul['hp']);
        $this->assertSame(1.15, $mul['def_spr']);
        $this->assertSame(1.00, $mul['atk_mag']);
        $this->assertSame('super_boss', $mul['tier']);
    }

    public function test_boss_below_super_boss_threshold_uses_normal_city_tier(): void
    {
        $service = new EnemyDurabilityService();
        $enemy = new Enemy(['id' => 424, 'role_key' => 'boss', 'is_boss' => true, 'level' => 141]);
        $mul = $service->multiplierFor($enemy, 10);

        $this->assertSame('city10', $mul['tier']);
        $this->assertSame(1.12, $mul['hp']);
        $this->assertSame(1.25, $mul['def_spr']);
    }

    public function test_global_switch_off_returns_neutral_multipliers_for_everything(): void
    {
        Config::set('enemy_durability.enabled', false);
        $service = new EnemyDurabilityService();

        $enemy = new Enemy(['id' => 1, 'role_key' => 'boss', 'is_boss' => true, 'level' => 240]);
        $mul = $service->multiplierFor($enemy, 9);

        $this->assertSame(1.0, $mul['hp']);
        $this->assertSame(1.0, $mul['def_spr']);
        $this->assertSame(1.0, $mul['atk_mag']);
    }

    public function test_each_city_switch_can_be_toggled_independently(): void
    {
        Config::set('enemy_durability.tiers.city8.enabled', false);
        $service = new EnemyDurabilityService();

        $city8Enemy = new Enemy(['id' => 1, 'role_key' => 'boss', 'is_boss' => true, 'level' => 113]);
        $city9Enemy = new Enemy(['id' => 2, 'role_key' => 'boss', 'is_boss' => true, 'level' => 127]);

        $this->assertSame(1.0, $service->multiplierFor($city8Enemy, 8)['hp']);
        $this->assertSame(1.10, $service->multiplierFor($city9Enemy, 9)['hp']); // city9は影響を受けない
    }

    public function test_unknown_role_in_managed_tier_falls_back_to_normal_multiplier(): void
    {
        $service = new EnemyDurabilityService();
        $enemy = new Enemy(['id' => 1, 'role_key' => 'otherworld_boss', 'is_boss' => false, 'level' => 100]);
        $mul = $service->multiplierFor($enemy, 8);

        // city8の'normal'倍率にフォールバックする
        $this->assertSame(1.08, $mul['hp']);
        $this->assertSame(1.00, $mul['def_spr']);
    }
}
