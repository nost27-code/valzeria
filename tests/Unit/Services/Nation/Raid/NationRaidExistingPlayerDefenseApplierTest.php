<?php

namespace Tests\Unit\Services\Nation\Raid;

use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationService;
use App\Services\JobArtV2CounterStanceState;
use App\Services\JobArtV2GuardState;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2UltimateCounterplayService;
use App\Services\Nation\Raid\NationRaidEnemyDamageResult;
use App\Services\Nation\Raid\Simulation\NationRaidExistingPlayerDefenseApplier;
use Tests\TestCase;

class NationRaidExistingPlayerDefenseApplierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
        ]);
    }

    public function test_mixed_action_uses_one_parry_roll_and_one_guard_charge_through_existing_pipeline(): void
    {
        [$player, $boss, $state] = $this->battle(1_000);
        $player->replaceCounterStanceState(new JobArtV2CounterStanceState(2, 0, 1.0));
        $player->replaceJobArtV2GuardState(new JobArtV2GuardState(0.40, 1));

        $result = $this->applier($player, $boss, $state)->apply(
            $this->damage([
                ['index' => 1, 'type' => 'physical', 'power' => 60, 'outcome' => 'hit', 'critical' => false, 'variance' => 100, 'damage' => 100],
                ['index' => 2, 'type' => 'magical', 'power' => 60, 'outcome' => 'hit', 'critical' => false, 'variance' => 100, 'damage' => 100],
            ], 200, ['healing_down_25_two_actions']),
            'dragon_core_backlight',
            1_000,
            100,
        );

        $this->assertSame(60, $result->damage->finalDamage);
        $this->assertSame(940, $result->playerHp);
        $this->assertTrue($result->defenseTrace['parry_succeeded']);
        $this->assertTrue($result->defenseTrace['guard_consumed']);
        $this->assertSame(0.40, $result->defenseTrace['guard_rate']);
        $this->assertNull($player->jobArtV2GuardState());
        $this->assertSame(['healing_down_25_two_actions'], $result->damage->appliedEffects);
    }

    public function test_guts_is_consumed_per_hit_and_returns_the_actual_surviving_hp(): void
    {
        [$player, $boss, $state] = $this->battle(100);
        $player->gutsReady = true;

        $result = $this->applier($player, $boss, $state)->apply(
            $this->damage([
                ['index' => 1, 'type' => 'magical', 'power' => 100, 'outcome' => 'hit', 'critical' => false, 'variance' => 100, 'damage' => 200],
            ], 200),
            'void_corrosion_orb',
            100,
            100,
        );

        $this->assertSame(1, $result->playerHp);
        $this->assertSame(200, $result->damage->finalDamage);
        $this->assertTrue($result->defenseTrace['guts_ready_before']);
        $this->assertTrue($result->defenseTrace['guts_triggered']);
        $this->assertFalse($player->gutsReady);
        $this->assertFalse($player->gutsJustTriggered);
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(int $playerHp): array
    {
        $player = new BattleActor('player', true, [
            'hp' => $playerHp,
            'max_hp' => $playerHp,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 1_000,
            'def' => 500,
            'agi' => 100,
            'mag' => 1_000,
            'spr' => 500,
            'luk' => 100,
            'current_job_id' => 60,
        ]);
        $boss = new BattleActor('boss', false, [
            'hp' => 1_000_000,
            'max_hp' => 1_000_000,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 2_200,
            'def' => 100,
            'agi' => 1_000,
            'mag' => 2_200,
            'spr' => 100,
            'luk' => 100,
            'species_keys' => ['dragon'],
        ]);

        return [$player, $boss, new BattleState($player, $boss, 'raid')];
    }

    private function applier(
        BattleActor $player,
        BattleActor $boss,
        BattleState $state,
    ): NationRaidExistingPlayerDefenseApplier {
        return new NationRaidExistingPlayerDefenseApplier(
            $player,
            $boss,
            $state,
            app(DamageApplicationService::class),
            app(JobArtV2ResourceService::class),
            app(JobArtV2UltimateCounterplayService::class),
        );
    }

    /**
     * @param  list<array{index:int,type:string,power:int,outcome:string,critical:bool,variance:int,damage:int}>  $hits
     * @param  list<string>  $effects
     */
    private function damage(array $hits, int $finalDamage, array $effects = []): NationRaidEnemyDamageResult
    {
        return new NationRaidEnemyDamageResult(
            beforeCap: array_sum(array_column($hits, 'damage')),
            cap: 1_000,
            afterCap: $finalDamage,
            finalReductionRate: 0.0,
            finalDamage: $finalDamage,
            hits: $hits,
            appliedEffects: $effects,
        );
    }
}
