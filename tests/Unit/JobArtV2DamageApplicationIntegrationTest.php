<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\ArenaNpcBattleService;
use App\Services\ArenaNpcRankingService;
use App\Services\Battle\ActionResolver;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationRequest;
use App\Services\Battle\DamageApplicationResult;
use App\Services\Battle\DamageApplicationService;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\HitResult;
use App\Services\BattleService;
use App\Services\ChampBattleService;
use App\Services\CharacterStatusService;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtService;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2SelectionService;
use App\Services\JobArtV2SpCostCalculator;
use App\Services\LevelService;
use App\Services\PvPBattleService;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class JobArtV2DamageApplicationIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.normalized_sp' => false,
        ]);
    }

    public function test_feature_gate_is_fail_closed_and_accepts_either_supported_participant(): void
    {
        $gate = new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog());
        $supported = $this->actor('supported', 24);
        $unsupported = $this->actor('unsupported', 1);

        $this->assertTrue($gate->usesDamageApplication($supported, $unsupported));
        $this->assertTrue($gate->usesDamageApplication($unsupported, $supported));
        $this->assertFalse($gate->usesDamageApplication($unsupported, $this->actor('other', 2)));

        foreach (['dynamic_single', 'hit_resolution', 'damage_application'] as $dependency) {
            config(["battle.job_art_v2.{$dependency}" => false]);
            $this->assertFalse($gate->usesDamageApplication($supported, $unsupported), $dependency);
            config(["battle.job_art_v2.{$dependency}" => true]);
        }
    }

    public function test_all_six_battle_paths_delegate_to_the_same_service_when_enabled(): void
    {
        $spy = $this->damageSpy();
        $source = $this->actor('source', 24);
        $target = $this->actor('target', null);

        $pve = app(BattleService::class);
        $this->setProperty($pve, BattleService::class, 'damageApplicationService', $spy);
        foreach (['pve', 'boss', 'tower'] as $battleType) {
            $this->invoke($pve, 'applyResolvedDamage', [
                $source,
                $target,
                new BattleState($source, $target, $battleType),
                1,
                DamageSourceType::NORMAL_ATTACK,
            ]);
        }

        $support = $this->support();
        $pvp = new PvPBattleService(
            Mockery::mock(CharacterStatusService::class),
            new DamageCalculator(),
            $support,
            $spy,
        );
        $this->invoke($pvp, 'applyResolvedDamage', [
            $source,
            $target,
            new BattleState($source, $target, 'pvp'),
            1,
            DamageSourceType::NORMAL_ATTACK,
        ]);

        $champ = new ChampBattleService(
            Mockery::mock(CharacterStatusService::class),
            new DamageCalculator(),
            Mockery::mock(LevelService::class),
            $support,
            $spy,
        );
        $this->invoke($champ, 'applyResolvedDamage', [
            $source,
            $target,
            new BattleState($source, $target, 'champ'),
            1,
            DamageSourceType::NORMAL_ATTACK,
        ]);

        $arena = new ArenaNpcBattleService(
            Mockery::mock(CharacterStatusService::class),
            new DamageCalculator(),
            Mockery::mock(ArenaNpcRankingService::class),
            $support,
            $spy,
        );
        $this->invoke($arena, 'applyResolvedDamage', [
            $source,
            $target,
            new BattleState($source, $target, 'arena_npc'),
            1,
            DamageSourceType::NORMAL_ATTACK,
        ]);

        $this->assertSame(
            ['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'],
            array_map(fn (DamageApplicationRequest $request): string => $request->battleType, $spy->requests),
        );
    }

    public function test_current_skill_and_job_art_keep_source_and_per_hit_metadata(): void
    {
        $spy = $this->damageSpy();
        $service = app(BattleService::class);
        $this->setProperty($service, BattleService::class, 'damageApplicationService', $spy);
        $attacker = $this->actor('attacker', 24);
        $defender = $this->actor('defender', null, 50_000);
        $state = new BattleState($attacker, $defender, 'pve');
        $skill = $this->damageSkill('current skill', 'active', 7001, 2);

        $this->invoke($service, 'executeSkillAction', [$attacker, $defender, $state, $skill]);

        $this->assertCount(2, $spy->requests);
        $this->assertSame([1, 2], array_column($spy->requests, 'hitIndex'));
        foreach ($spy->requests as $request) {
            $this->assertSame(DamageSourceType::JOB_SKILL, $request->sourceType);
            $this->assertSame(7001, $request->sourceId);
            $this->assertSame(2, $request->hitCount);
        }

        $spy->requests = [];
        $art = $this->damageSkill('job art', 'job_art', 9001, 3);
        $this->invoke($service, 'executeJobArtDamageTemplate', [
            $attacker,
            $defender,
            $state,
            $art,
            180,
            'physical',
            true,
        ]);

        $this->assertCount(3, $spy->requests);
        $this->assertSame([1, 2, 3], array_column($spy->requests, 'hitIndex'));
        foreach ($spy->requests as $request) {
            $this->assertSame(DamageSourceType::JOB_ART, $request->sourceType);
            $this->assertSame(9001, $request->sourceId);
            $this->assertSame(HitResult::HIT, $request->hitResult);
            $this->assertSame(3, $request->hitCount);
        }
    }

    public function test_zero_damage_miss_and_non_damage_art_do_not_call_the_service(): void
    {
        $spy = $this->damageSpy();
        $service = app(BattleService::class);
        $this->setProperty($service, BattleService::class, 'damageApplicationService', $spy);
        $attacker = $this->actor('attacker', 24);
        $defender = $this->actor('defender', null);
        $state = new BattleState($attacker, $defender, 'pve');

        $this->invoke($service, 'applyResolvedDamage', [
            $attacker,
            $defender,
            $state,
            0,
            DamageSourceType::JOB_ART,
            9001,
            HitResult::MISS,
        ]);

        $heal = new Skill([
            'name' => 'healing art',
            'skill_type' => 'job_art',
            'effect_template' => 'HEAL',
            'damage_type' => 'heal',
            'power' => 100,
            'power_multiplier' => 0,
            'hit_count' => 0,
        ]);
        $heal->setAttribute('id', 9002);
        $attacker->jobArtRates[9002] = 1.0;
        $this->invoke($service, 'executeJobArtAction', [$attacker, $defender, $state, $heal]);

        $this->assertCount(0, $spy->requests);
    }

    public function test_flag_off_bypasses_the_service_and_keeps_hp_logs_death_and_rng_state(): void
    {
        config(['battle.job_art_v2.damage_application' => false]);
        $spy = $this->damageSpy();
        $service = app(BattleService::class);
        $this->setProperty($service, BattleService::class, 'damageApplicationService', $spy);
        $source = $this->actor('source', 24);
        $target = $this->actor('target', null, 100);
        $state = new BattleState($source, $target, 'pve');
        $state->addLog('before');

        mt_srand(8844);
        $expectedRoll = mt_rand();
        mt_srand(8844);
        $this->invoke($service, 'applyResolvedDamage', [
            $source,
            $target,
            $state,
            150,
            DamageSourceType::JOB_ART,
            9001,
            HitResult::HIT,
        ]);

        $this->assertSame(0, $target->hp);
        $this->assertTrue($target->isDead());
        $this->assertSame(['before'], $state->logs);
        $this->assertSame($expectedRoll, mt_rand());
        $this->assertCount(0, $spy->requests);
    }

    private function support(): JobArtBattleSupportService
    {
        return new JobArtBattleSupportService(
            Mockery::mock(JobArtService::class),
            new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog()),
            app(JobArtV2SelectionService::class),
            app(JobArtV2SpCostCalculator::class),
            app(ActionResolver::class),
        );
    }

    private function damageSpy(): DamageApplicationService
    {
        return new class extends DamageApplicationService
        {
            /** @var list<DamageApplicationRequest> */
            public array $requests = [];

            public function apply(DamageApplicationRequest $request): DamageApplicationResult
            {
                $this->requests[] = $request;

                return parent::apply($request);
            }
        };
    }

    private function damageSkill(string $name, string $type, int $id, int $hits): Skill
    {
        $skill = new Skill([
            'name' => $name,
            'skill_type' => $type,
            'effect_template' => 'MULTI_HIT',
            'damage_type' => 'physical',
            'power' => 180,
            'power_multiplier' => 1.8,
            'hit_count' => $hits,
        ]);
        $skill->setAttribute('id', $id);

        return $skill;
    }

    private function actor(string $name, ?int $jobId, int $hp = 10_000): BattleActor
    {
        return new BattleActor($name, true, [
            'hp' => $hp,
            'max_hp' => $hp,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
            'current_job_id' => $jobId,
        ]);
    }

    private function setProperty(object $object, string $class, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty($class, $property);
        $reflection->setValue($object, $value);
    }

    private function invoke(object $service, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $arguments);
    }
}
