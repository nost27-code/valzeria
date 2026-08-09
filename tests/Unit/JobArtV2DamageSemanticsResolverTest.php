<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\ActionResolver;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\HitResult;
use App\Services\BattleService;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2DamageSemanticsResolver;
use App\Services\JobArtV2FieldService;
use App\Services\JobArtV2PenetrationService;
use App\Services\JobArtV2ResourceService;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class JobArtV2DamageSemanticsResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
            'battle.job_art_v2.penetration' => true,
        ]);
    }

    public function test_current_job_sixty_one_trusted_chain_resolves_to_mag_spr_magical(): void
    {
        $resolver = app(JobArtV2DamageSemanticsResolver::class);
        $actor = $this->actor(61);

        foreach ([1, 5, 9] as $rank) {
            $skill = $this->art(61, $rank);
            $this->attachAsCurrent($actor, $skill);

            $this->assertSame([
                'attack_stat' => 'mag',
                'defense_stat' => 'spr',
                'damage_category' => 'magical',
            ], $resolver->forExecution($actor, $skill), (string) $rank);

            $skill->setAttribute('job_art_origin', 'current');
            $this->assertSame('magical', $resolver->forDisplay(61, $skill)['damage_category'] ?? null);
        }
    }

    public function test_execution_copy_is_magical_without_mutating_the_legacy_master_or_adding_drain(): void
    {
        $actor = $this->actor(61);
        $skill = $this->art(61, 9);
        $skill->effect_template = 'DRAIN';
        $skill->drain_hp_rate = 0.30;
        $this->attachAsCurrent($actor, $skill);

        $execution = app(JobArtBattleSupportService::class)->skillForExecution($actor, $skill);

        $this->assertSame('MAGICAL_DAMAGE', $execution->effect_template);
        $this->assertSame('magical', $execution->damage_type);
        $this->assertSame(0.0, (float) $execution->drain_hp_rate);
        $this->assertSame('DRAIN', $skill->effect_template);
        $this->assertSame('physical', $skill->damage_type);
        $this->assertSame(0.30, (float) $skill->drain_hp_rate);
    }

    public function test_pve_runtime_uses_mag_against_spr_for_the_v2_current_job_override(): void
    {
        $attacker = $this->actor(61, str: 10, def: 100, mag: 1_000, spr: 100);
        $defender = $this->actor(null, str: 100, def: 10, mag: 100, spr: 500, hp: 10_000, player: false);
        $skill = $this->art(61, 1, 225);
        $this->attachAsCurrent($attacker, $skill);
        $state = new BattleState($attacker, $defender, 'pve');
        app(JobArtV2ResourceService::class)->beginAction($attacker, $state);
        $service = app(BattleService::class);
        $hitResolver = Mockery::mock(ActionResolver::class);
        $hitResolver->shouldReceive('resolveJobArt')->once()->andReturn(HitResult::HIT);
        (new ReflectionProperty(BattleService::class, 'jobArtActionResolver'))->setValue($service, $hitResolver);

        mt_srand(61_001);
        $this->invoke($service, 'executeJobArtAction', [$attacker, $defender, $state, $skill]);

        $this->assertGreaterThan(1_000, $defender->totalDamageTaken);
        $this->assertSame(10_000, $attacker->hp);
        $this->assertSame(4, $attacker->getResource('eclipse'));
    }

    public function test_flag_off_inherited_and_untrusted_paths_remain_legacy_physical(): void
    {
        $resolver = app(JobArtV2DamageSemanticsResolver::class);
        $actor = $this->actor(61);
        $rankNine = $this->art(61, 9);
        $this->attachAsCurrent($actor, $rankNine);

        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources'] as $dependency) {
            $this->enableCombatChain();
            config(["battle.job_art_v2.{$dependency}" => false]);
            $this->assertNull($resolver->forExecution($actor, $rankNine), $dependency);
            $this->assertSame('physical', app(JobArtBattleSupportService::class)->skillForExecution($actor, $rankNine)->damage_type, $dependency);
        }

        $this->enableCombatChain();
        $actor->jobArtOrigins[(int) $rankNine->id] = 'inherited';
        $this->assertNull($resolver->forExecution($actor, $rankNine));
        $this->assertSame('physical', app(JobArtBattleSupportService::class)->skillForExecution($actor, $rankNine)->damage_type);

        $rankNine->setAttribute('job_art_origin', 'inherited');
        $this->assertNull($resolver->forDisplay(61, $rankNine));

        $outside = $this->actor(64);
        $outside->jobArtOrigins[(int) $rankNine->id] = 'current';
        $this->assertNull($resolver->forExecution($outside, $rankNine));

        $untrusted = $this->art(61, 3);
        $this->attachAsCurrent($actor, $untrusted);
        $this->assertNull($resolver->forExecution($actor, $untrusted));
    }

    public function test_star_light_owner_bonus_uses_the_resolved_magical_action_scope(): void
    {
        $owner = $this->actor(61);
        $fieldJob = $this->actor(53, player: false);
        $state = new BattleState($owner, $fieldJob, 'pve');
        $state->turnCount = 1;
        $fields = app(JobArtV2FieldService::class);
        $this->assertTrue($fields->deployPrimary($owner, $state, 'star_light', 5_301, 1)->applied);
        app(JobArtV2ResourceService::class)->beginAction($owner, $state);

        $skill = $this->art(61, 5);
        $this->attachAsCurrent($owner, $skill);
        $execution = app(JobArtBattleSupportService::class)->skillForExecution($owner, $skill, $state);
        $fields->markSkillAction($owner, $state, $execution);

        $this->assertSame(1_100, $fields->modifyDamage($owner, $state, 1_000, DamageSourceType::JOB_ART));

        $owner->jobArtOrigins[(int) $skill->id] = 'inherited';
        $legacyExecution = app(JobArtBattleSupportService::class)->skillForExecution($owner, $skill, $state);
        $fields->markSkillAction($owner, $state, $legacyExecution);
        $this->assertSame(1_000, $fields->modifyDamage($owner, $state, 1_000, DamageSourceType::JOB_ART));
    }

    public function test_sixty_one_never_receives_the_physical_penetration_override(): void
    {
        $actor = $this->actor(61);
        $defender = $this->actor(null, def: 2_000, spr: 1_500, player: false);

        foreach ([1, 5, 9] as $rank) {
            $skill = $this->art(61, $rank);
            $this->attachAsCurrent($actor, $skill);
            $this->assertSame([
                'def' => null,
                'spr' => null,
                'penetration_rate' => null,
            ], app(JobArtV2PenetrationService::class)->defenseOverrides($actor, $defender, $skill));
        }
    }

    public function test_sixty_four_and_existing_vertical_slice_semantics_remain_unchanged(): void
    {
        foreach ([24 => 'heal', 53 => 'magical', 62 => 'physical', 64 => 'physical', 85 => 'magical'] as $jobId => $expectedType) {
            $actor = $this->actor($jobId);
            $skill = $this->art($jobId, 9);
            $skill->damage_type = $expectedType;
            $skill->effect_template = match ($expectedType) {
                'heal' => 'HEAL',
                'magical' => 'MAGICAL_DAMAGE',
                default => 'PHYSICAL_DAMAGE',
            };
            $this->attachAsCurrent($actor, $skill);

            $this->assertNull(app(JobArtV2DamageSemanticsResolver::class)->forExecution($actor, $skill), (string) $jobId);
            $this->assertSame($expectedType, app(JobArtBattleSupportService::class)->skillForExecution($actor, $skill)->damage_type, (string) $jobId);
        }
    }

    public function test_all_six_battle_paths_share_the_two_resolved_execution_entry_points(): void
    {
        $battle = (string) file_get_contents(base_path('app/Services/BattleService.php'));
        $tower = (string) file_get_contents(base_path('app/Services/TowerBattleService.php'));
        $support = (string) file_get_contents(base_path('app/Services/JobArtBattleSupportService.php'));

        $this->assertStringContainsString('applyForExecution($attacker, $sourceSkill, $skill)', $battle);
        $this->assertStringContainsString('class TowerBattleService extends BattleService', $tower);
        $this->assertStringContainsString('applyForExecution($actor, $skill, $executionSkill)', $support);

        foreach (['PvPBattleService.php', 'ChampBattleService.php', 'ArenaNpcBattleService.php'] as $file) {
            $source = (string) file_get_contents(base_path("app/Services/{$file}"));
            $this->assertStringContainsString('skillForExecution(', $source, $file);
        }
    }

    public function test_semantics_resolution_does_not_consume_randomness(): void
    {
        $actor = $this->actor(61);
        $skill = $this->art(61, 9);
        $this->attachAsCurrent($actor, $skill);
        mt_srand(61_009);
        $expected = mt_rand();

        mt_srand(61_009);
        app(JobArtV2DamageSemanticsResolver::class)->forExecution($actor, $skill);
        app(JobArtBattleSupportService::class)->skillForExecution($actor, $skill);

        $this->assertSame($expected, mt_rand());
    }

    private function art(int $jobId, int $rank, int $power = 355): Skill
    {
        $skill = new Skill([
            'name' => "job-{$jobId}-rank-{$rank}",
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'effect_template' => 'PHYSICAL_DAMAGE',
            'damage_type' => 'physical',
            'power' => $power,
            'power_multiplier' => $power / 100,
            'hit_count' => 1,
            'drain_hp_rate' => 0,
        ]);
        $skill->setAttribute('id', ($jobId * 100) + $rank);

        return $skill;
    }

    private function attachAsCurrent(BattleActor $actor, Skill $skill): void
    {
        $actor->jobArtOrigins[(int) $skill->id] = 'current';
        $actor->jobArtRates[(int) $skill->id] = 1.0;
    }

    private function actor(
        ?int $jobId,
        int $str = 100,
        int $def = 100,
        int $mag = 100,
        int $spr = 100,
        int $hp = 10_000,
        bool $player = true,
    ): BattleActor {
        return new BattleActor('actor', $player, [
            'hp' => $hp,
            'max_hp' => $hp,
            'mp' => 1_000,
            'max_mp' => 1_000,
            'str' => $str,
            'def' => $def,
            'agi' => 100,
            'mag' => $mag,
            'spr' => $spr,
            'luk' => 100,
            'current_job_id' => $jobId,
        ]);
    }

    private function invoke(object $service, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $arguments);
    }

    private function enableCombatChain(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
        ]);
    }
}
