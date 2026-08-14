<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\Battle\DamageCalculator;
use App\Services\JobArtV2BreakDebuffState;
use App\Services\JobArtV2TimedEffectState;
use Tests\TestCase;

class JobArtV2EffectiveStatIntegrationTest extends TestCase
{
    public function test_percentage_defense_applies_condition_break_and_timed_modifiers_once(): void
    {
        config([
            'battle.pve_enemy_percentage_defense.enabled' => true,
            'battle.pve_enemy_percentage_defense.defense_coefficient' => 3.5,
        ]);

        $enemy = new BattleActor('enemy', false, [
            'str' => 1_000,
            'mag' => 1_000,
            'max_hp' => 10_000,
        ]);
        $player = new BattleActor('player', true, [
            'def' => 100,
            'spr' => 200,
            'max_hp' => 10_000,
        ]);
        $player->conditions = [
            'def_down' => ['rate' => 0.10],
            'spr_down' => ['rate' => 0.10],
        ];
        $player->replaceBreakDebuffState(new JobArtV2BreakDebuffState(0.20, 2, 1));
        $player->replaceJobArtV2TimedEffect(new JobArtV2TimedEffectState(
            key: 'guard',
            statModifiers: ['def' => 0.25, 'spr' => 0.25],
            appliedRound: 1,
            remainingRounds: 2,
            sourceActionId: 1,
            sourceSkillId: 1,
            removable: true,
            strength: 25,
        ));

        // All modifiers are additive against base and clamped once:
        // -10% condition -20% break +25% guard = -5%.
        $this->assertSame(95.0, $player->effectivePercentageDef());
        $this->assertSame(190.0, $player->effectivePercentageSpr());

        $calculator = app(DamageCalculator::class);
        mt_srand(41);
        $physical = $calculator->calculatePhysicalDamage($enemy, $player);
        mt_srand(41);
        $expectedPhysical = (int) floor(((1_000 * 1_000) / (1_000 + (3.5 * 95))) * (rand(85, 115) / 100));
        $this->assertSame($expectedPhysical, $physical);

        mt_srand(73);
        $magical = $calculator->calculateMagicalDamage($enemy, $player);
        mt_srand(73);
        $expectedMagical = (int) floor(((1_000 * 1_000) / (1_000 + (3.5 * 190))) * (rand(85, 115) / 100));
        $this->assertSame($expectedMagical, $magical);
    }

    public function test_percentage_defense_keeps_zero_while_effective_hybrid_stats_are_flag_selectable(): void
    {
        $actor = new BattleActor('actor', true, [
            'str' => 100,
            'mag' => 200,
            'def' => 0,
            'spr' => 0,
            'max_hp' => 1_000,
        ]);
        $actor->replaceBreakDebuffState(new JobArtV2BreakDebuffState(0.50, 2, 1));
        $actor->replaceJobArtV2TimedEffect(new JobArtV2TimedEffectState(
            key: 'battle_drive',
            statModifiers: ['str' => 1.50, 'def' => 0.25, 'spr' => 0.25],
            appliedRound: 1,
            remainingRounds: 2,
            sourceActionId: 1,
            sourceSkillId: 1,
            removable: true,
            strength: 150,
        ));

        $this->assertSame(0.0, $actor->effectivePercentageDef());
        $this->assertSame(0.0, $actor->effectivePercentageSpr());

        // Flag-off callers pass false and retain the raw legacy hybrid value.
        $this->assertSame(200, $actor->hybridAttackPower('max', false));
        $this->assertSame(150, $actor->hybridAttackPower('average', false));
        // Role-enabled callers use effective STR/MAG including timed effects.
        $this->assertSame(200, $actor->hybridAttackPower('max', true));
        $this->assertSame(180, $actor->hybridAttackPower('average', true));
    }

    public function test_final_stat_caps_are_plus_sixty_minus_forty_and_speed_minus_twenty_five_percent(): void
    {
        $actor = new BattleActor('actor', true, [
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'max_hp' => 1_000,
        ]);
        $actor->replaceJobArtV2TimedEffect(new JobArtV2TimedEffectState(
            key: 'first_buff',
            statModifiers: ['str' => 0.40, 'mag' => 0.40],
            appliedRound: 1,
            remainingRounds: 3,
            sourceActionId: 1,
            sourceSkillId: 1,
            removable: true,
            strength: 40,
        ));
        $actor->replaceJobArtV2TimedEffect(new JobArtV2TimedEffectState(
            key: 'second_buff',
            statModifiers: ['str' => 0.40, 'mag' => 0.40],
            appliedRound: 1,
            remainingRounds: 3,
            sourceActionId: 2,
            sourceSkillId: 2,
            removable: true,
            strength: 40,
        ));
        $actor->conditions = [
            'def_down' => ['rate' => 0.80],
            'spr_down' => ['rate' => 0.80],
            'slow' => ['rate' => 0.80],
        ];

        $this->assertSame(160, $actor->effectiveStr());
        $this->assertSame(160, $actor->effectiveMag());
        $this->assertSame(60, $actor->effectiveDef());
        $this->assertSame(60, $actor->effectiveSpr());
        $this->assertSame(75, $actor->effectiveAgi());
    }

    public function test_all_battle_routes_gate_hybrid_effective_stats_on_role_effects(): void
    {
        $battleService = file_get_contents(app_path('Services/BattleService.php'));
        $this->assertGreaterThanOrEqual(2, substr_count($battleService, '->hybridAttackPower('));
        $this->assertGreaterThanOrEqual(2, substr_count($battleService, 'jobArtV2RoleEffectService->enabledFor($attacker)'));

        foreach (['PvPBattleService.php', 'ChampBattleService.php', 'ArenaNpcBattleService.php'] as $file) {
            $source = file_get_contents(app_path('Services/'.$file));
            $this->assertStringContainsString('->hybridAttackPower(', $source, $file);
            $this->assertStringContainsString('jobArtBattleSupport->usesRoleEffects($attacker)', $source, $file);
        }
    }

    public function test_existing_job_art_heal_routes_use_effective_spr_only_when_role_effects_are_enabled(): void
    {
        $battleService = file_get_contents(app_path('Services/BattleService.php'));
        $this->assertStringContainsString(
            '$healingSpr = $this->jobArtV2RoleEffectService->enabledFor($attacker)',
            $battleService,
        );
        $this->assertStringContainsString('? $attacker->effectiveSpr()', $battleService);
        $this->assertStringContainsString(': $attacker->spr;', $battleService);

        foreach (['PvPBattleService.php', 'ChampBattleService.php', 'ArenaNpcBattleService.php'] as $file) {
            $source = file_get_contents(app_path('Services/'.$file));
            $this->assertStringContainsString(
                '$healingSpr = $this->jobArtBattleSupport->usesRoleEffects($attacker)',
                $source,
                $file,
            );
            $this->assertStringContainsString('? $attacker->effectiveSpr()', $source, $file);
            $this->assertStringContainsString(': $attacker->spr;', $source, $file);
        }

        $tower = file_get_contents(app_path('Services/TowerBattleService.php'));
        $this->assertStringContainsString('class TowerBattleService extends BattleService', $tower);
    }
}
