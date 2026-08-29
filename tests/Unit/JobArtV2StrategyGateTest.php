<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\JobArtV2BattleRules;
use App\Services\JobArtV2StrategyService;
use Tests\TestCase;

class JobArtV2StrategyGateTest extends TestCase
{
    public function test_detailed_strategy_uses_its_own_flag_even_when_rank_five_v6_is_off(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.rank5_v6' => false,
            'battle.job_art_v2.detailed_strategy' => false,
            'battle.job_art_v2.sp_power_scaling.enabled' => false,
            'battle.job_art_v2.pvp_set' => false,
        ]);
        $actor = new BattleActor('player', true, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 1_000,
            'max_mp' => 1_000,
            'current_job_id' => 1,
        ]);
        $actor->jobArtStrategy = [
            'mode' => 'auto',
            'sp_policy' => 'aggressive',
            'sp_output' => 'none',
            'settings' => app(JobArtV2StrategyService::class)->autoSettings(),
        ];
        $ultimate = new Skill([
            'job_id' => 1,
            'skill_type' => 'job_art',
            'learn_rank' => 9,
        ]);
        $service = app(JobArtV2StrategyService::class);

        $this->assertNull($service->profileFor($actor));
        $this->assertNull($service->guaranteedUltimateRate($actor, $ultimate, static fn (): bool => true));
        $this->assertSame(60, app(JobArtV2BattleRules::class)->activationRateFor($ultimate, 1, 'current'));
        $this->assertFalse((bool) config('battle.job_art_v2.rank5_v6'));

        config(['battle.job_art_v2.detailed_strategy' => true]);

        $this->assertNotNull($service->profileFor($actor));
        $this->assertSame(100, $service->guaranteedUltimateRate($actor, $ultimate, static fn (): bool => true));
    }
}
