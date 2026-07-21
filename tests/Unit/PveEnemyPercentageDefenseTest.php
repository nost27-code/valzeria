<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\BattleService;
use App\Services\TowerBattleService;
use Tests\TestCase;

class PveEnemyPercentageDefenseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.pve_enemy_percentage_defense.enabled' => false,
            'battle.pve_enemy_percentage_defense.defense_coefficient' => 0.8,
        ]);
    }

    public function test_default_configuration_is_enabled_and_uses_coefficient_zero_point_eight(): void
    {
        $configFile = require base_path('config/battle.php');

        $this->assertTrue($configFile['pve_enemy_percentage_defense']['enabled']);
        $this->assertSame(0.8, $configFile['pve_enemy_percentage_defense']['defense_coefficient']);
    }

    public function test_disabled_switch_matches_the_legacy_physical_and_magical_formulas(): void
    {
        $calculator = app(DamageCalculator::class);
        $enemy = $this->enemy(str: 800, mag: 700);
        $player = $this->player(def: 300, spr: 250);
        $player->isDefending = true;
        $player->damageReductionRate = 20;

        mt_srand(20260714);
        $physical = $calculator->calculatePhysicalDamage($enemy, $player, 150, true);
        mt_srand(20260714);
        $expectedPhysical = $this->legacyDamage(800, 300, $player, 150, true);
        $this->assertSame($expectedPhysical, $physical);

        mt_srand(20260714);
        $magical = $calculator->calculateMagicalDamage($enemy, $player, 130, true);
        mt_srand(20260714);
        $expectedMagical = $this->legacyDamage(700, 250, $player, 130, true);
        $this->assertSame($expectedMagical, $magical);
    }

    public function test_zero_defense_and_spirit_keep_the_enemy_attack_as_base_damage(): void
    {
        $this->enablePercentageDefense();
        $calculator = app(DamageCalculator::class);
        $enemy = $this->enemy(str: 2_217, mag: 1_234);
        $player = $this->player(def: 0, spr: 0);

        $this->assertSame(2217.0, $this->percentageBaseDamage($calculator, 2217, 0.0, 0.8));
        $this->assertSame(1234.0, $this->percentageBaseDamage($calculator, 1234, 0.0, 0.8));

        mt_srand(77);
        $physical = $calculator->calculatePhysicalDamage($enemy, $player);
        mt_srand(77);
        $this->assertSame((int) floor(2217 * (rand(85, 115) / 100)), $physical);

        mt_srand(77);
        $magical = $calculator->calculateMagicalDamage($enemy, $player);
        mt_srand(77);
        $this->assertSame((int) floor(1234 * (rand(85, 115) / 100)), $magical);
    }

    public function test_percentage_formula_is_monotonic_and_matches_the_requested_reference_values(): void
    {
        $this->enablePercentageDefense();
        $calculator = app(DamageCalculator::class);

        $damage1700 = $this->percentageBaseDamage($calculator, 2217, 1700.0, 0.8);
        $damage4370 = $this->percentageBaseDamage($calculator, 2217, 4370.0, 0.8);
        $damage7600 = $this->percentageBaseDamage($calculator, 2217, 7600.0, 0.8);

        $this->assertEqualsWithDelta(1374.0, $damage1700, 1.0);
        $this->assertEqualsWithDelta(860.0, $damage4370, 1.0);
        $this->assertEqualsWithDelta(592.0, $damage7600, 1.0);
        $this->assertGreaterThan($damage4370, $damage1700);
        $this->assertGreaterThan($damage7600, $damage4370);
        $this->assertGreaterThan(0, $this->percentageBaseDamage($calculator, 2217, 10_000_000.0, 0.8));

        $enemy = $this->enemy(mag: 2217);
        mt_srand(9001);
        $magical1700 = $calculator->calculateMagicalDamage($enemy, $this->player(spr: 1700));
        mt_srand(9001);
        $magical4370 = $calculator->calculateMagicalDamage($enemy, $this->player(spr: 4370));
        mt_srand(9001);
        $magical7600 = $calculator->calculateMagicalDamage($enemy, $this->player(spr: 7600));

        $this->assertGreaterThan($magical4370, $magical1700);
        $this->assertGreaterThan($magical7600, $magical4370);
    }

    public function test_critical_halves_defense_before_percentage_reduction_and_keeps_modifier_order(): void
    {
        $this->enablePercentageDefense();
        $calculator = app(DamageCalculator::class);
        $enemy = $this->enemy(str: 1000, mag: 1000);
        $player = $this->player(def: 500, spr: 500);
        $player->isDefending = true;
        $player->damageReductionRate = 20;

        mt_srand(12345);
        $actual = $calculator->calculatePhysicalDamage($enemy, $player, 150, true);
        mt_srand(12345);
        $expected = (1000 * 1000) / (1000 + (0.8 * (500 / 2)));
        $expected *= 1.5;
        $expected *= 1.5;
        $expected *= rand(85, 115) / 100;
        $expected *= 0.5;
        $expected *= 0.8;
        $this->assertSame((int) floor($expected), $actual);

        mt_srand(12345);
        $magicalActual = $calculator->calculateMagicalDamage($enemy, $player, 150, true);
        mt_srand(12345);
        $magicalExpected = (1000 * 1000) / (1000 + (0.8 * (500 / 2)));
        $magicalExpected *= 1.5;
        $magicalExpected *= 1.5;
        $magicalExpected *= rand(85, 115) / 100;
        $magicalExpected *= 0.5;
        $magicalExpected *= 0.8;
        $this->assertSame((int) floor($magicalExpected), $magicalActual);
    }

    public function test_player_to_enemy_and_pvp_damage_do_not_use_the_new_formula(): void
    {
        $calculator = app(DamageCalculator::class);
        $player = $this->player(str: 900, mag: 800, def: 300, spr: 300);
        $enemy = $this->enemy(str: 700, mag: 700, def: 400, spr: 350);

        $this->enablePercentageDefense();
        mt_srand(456);
        $playerToEnemy = $calculator->calculatePhysicalDamage($player, $enemy);
        mt_srand(456);
        $this->assertSame($this->legacyDamage(900, 400, $enemy, 100, false), $playerToEnemy);

        config(['battle.pve_enemy_percentage_defense.enabled' => false]);
        mt_srand(789);
        $duelOff = $calculator->calculateDuelDamage($enemy, $player, 'physical');
        mt_srand(789);
        $rankOff = $calculator->calculateRankBattleDamage($enemy, $player, 'physical');

        $this->enablePercentageDefense();
        mt_srand(789);
        $duelOn = $calculator->calculateDuelDamage($enemy, $player, 'physical');
        mt_srand(789);
        $rankOn = $calculator->calculateRankBattleDamage($enemy, $player, 'physical');

        $this->assertSame($duelOff, $duelOn);
        $this->assertSame($rankOff, $rankOn);
    }

    public function test_damage_over_time_does_not_use_the_percentage_formula(): void
    {
        $this->enablePercentageDefense();
        $player = $this->player(def: 9_999, spr: 9_999);
        $enemy = $this->enemy();
        $player->conditions = [
            'burn' => ['turns' => 2, 'rate' => 0.04],
            'poison' => ['turns' => 2, 'stacks' => 1, 'rate' => 0.01],
            'bleed' => ['turns' => 2, 'rate' => 0.03],
        ];
        $state = new BattleState($player, $enemy, 'pve');

        $method = new \ReflectionMethod(BattleService::class, 'tickPlayerConditionsAfterAction');
        $method->setAccessible(true);
        $method->invoke(app(BattleService::class), $player, $state, true);

        $this->assertSame(9200, $player->hp);
    }

    public function test_current_hp_percentage_damage_does_not_use_the_percentage_formula(): void
    {
        $this->enablePercentageDefense();
        $enemy = $this->enemy(str: 9_999, mag: 9_999);
        $player = $this->player(def: 9_999, spr: 9_999);
        $state = new BattleState($player, $enemy, 'pve');

        $method = new \ReflectionMethod(BattleService::class, 'executeCurrentHpPercentAttack');
        $method->setAccessible(true);
        $method->invoke(app(BattleService::class), $enemy, $player, $state, 25);

        $this->assertSame(7500, $player->hp);
    }

    public function test_star_tree_tower_enemy_direct_attack_uses_the_percentage_formula(): void
    {
        $this->enablePercentageDefense();
        $enemy = $this->enemy(str: 2_217, def: 10);
        $enemy->agi = 1000;
        $enemy->luk = 0;
        $player = $this->player(def: 1_700);
        $player->agi = 1;
        $player->luk = 100;
        $state = new BattleState($player, $enemy, 'pve');

        mt_srand(1);
        $method = new \ReflectionMethod(TowerBattleService::class, 'executePhysicalAttack');
        $method->setAccessible(true);
        $method->invoke(app(TowerBattleService::class), $enemy, $player, $state, 100);

        $expected = (int) floor(((2217 * 2217) / (2217 + (0.8 * 1700))) * 0.93);
        $this->assertSame($expected, 10_000 - $player->hp);
    }

    private function enablePercentageDefense(): void
    {
        config([
            'battle.pve_enemy_percentage_defense.enabled' => true,
            'battle.pve_enemy_percentage_defense.defense_coefficient' => 0.8,
        ]);
    }

    private function percentageBaseDamage(DamageCalculator $calculator, int $attack, float $defense, float $coefficient): float
    {
        $method = new \ReflectionMethod($calculator, 'calculatePveEnemyPercentageBaseDamage');
        $method->setAccessible(true);

        return $method->invoke($calculator, $attack, $defense, $coefficient);
    }

    private function legacyDamage(int $attack, int $defense, BattleActor $defender, int $skillPower, bool $critical): int
    {
        if ($critical) {
            $defense = (int) ($defense * 0.5);
        }

        $damage = max(1, $attack - ($defense / 2)) * ($skillPower / 100);
        if ($critical) {
            $damage *= 1.5;
        }
        $damage = (int) ($damage * (rand(85, 115) / 100));
        if ($defender->isDefending) {
            $damage = (int) ($damage * 0.5);
        }
        if ($defender->damageReductionRate > 0) {
            $damage = (int) ($damage * (1 - ($defender->damageReductionRate / 100)));
        }

        return max(1, $damage);
    }

    private function enemy(int $str = 10, int $mag = 10, int $def = 10, int $spr = 10): BattleActor
    {
        return new BattleActor('敵', false, compact('str', 'mag', 'def', 'spr') + ['max_hp' => 10000]);
    }

    private function player(int $str = 10, int $mag = 10, int $def = 10, int $spr = 10): BattleActor
    {
        return new BattleActor('冒険者', true, compact('str', 'mag', 'def', 'spr') + ['max_hp' => 10000]);
    }
}
