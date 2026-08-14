<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\ArenaNpcBattleService;
use App\Services\Battle\ActionResolver;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\BattleService;
use App\Services\ChampBattleService;
use App\Services\CharacterStatusService;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtService;
use App\Services\JobArtV2ActiveEvasionProvider;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2HitRandomSource;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2SelectionService;
use App\Services\JobArtV2SpCostCalculator;
use App\Services\LevelService;
use App\Services\PvPBattleService;
use App\Services\ArenaNpcRankingService;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class JobArtV2HitResolutionIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.normalized_sp' => false,
        ]);
    }

    public function test_pve_miss_consumes_the_art_and_keeps_self_effects_without_fallback_or_target_effects(): void
    {
        $random = $this->random([100]);
        $resolver = $this->resolver($random);
        $service = new class(
            Mockery::mock(CharacterStatusService::class),
            new DamageCalculator(),
            Mockery::mock(JobArtService::class),
            new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog()),
            app(JobArtV2SelectionService::class),
            app(JobArtV2SpCostCalculator::class),
            $resolver,
        ) extends BattleService {
            public bool $specialFallback = false;
            public bool $normalFallback = false;

            public function act(BattleActor $attacker, BattleActor $defender, BattleState $state): void
            {
                $this->executeAction($attacker, $defender, $state);
            }

            protected function executeSkillAction(BattleActor $attacker, BattleActor $defender, BattleState $state, Skill $skill): void
            {
                $this->specialFallback = true;
            }

            protected function executeNormalAttack(BattleActor $attacker, BattleActor $defender, BattleState $state, int $powerMultiplier = 100): void
            {
                $this->normalFallback = true;
            }
        };
        [$attacker, $defender, $state, $skill] = $this->battle('pve');

        $service->act($attacker, $defender, $state);

        $this->assertSame(94, $attacker->mp);
        $this->assertSame(110, $attacker->str);
        $this->assertSame(5000, $defender->hp);
        $this->assertSame(100, $defender->def);
        $this->assertSame(1, $state->jobArtUseCounts[$skill->id]);
        $this->assertArrayNotHasKey($skill->id, $state->jobArtCooldowns);
        $this->assertFalse($service->specialFallback);
        $this->assertFalse($service->normalFallback);
        $this->assertSame(1, $random->calls);
        $this->assertStringContainsString('試作複合奥義は外れた', implode("\n", $state->logs));
    }

    public function test_pve_multi_hit_uses_one_resolution_and_applies_every_hit_when_it_lands(): void
    {
        $random = $this->random([1]);
        $resolver = $this->resolver($random);
        $service = app(BattleService::class);
        $property = new \ReflectionProperty(BattleService::class, 'jobArtActionResolver');
        $property->setValue($service, $resolver);
        [$attacker, $defender, $state, $skill] = $this->battle('pve', 'MULTI_HIT');

        $this->invoke($service, 'executeJobArtAction', [$attacker, $defender, $state, $skill]);

        $damageLogs = array_filter($state->logs, fn (string $log): bool => str_contains($log, 'のダメージ'));
        $this->assertSame(3, count($damageLogs));
        $this->assertLessThan(5000, $defender->hp);
        $this->assertSame(1, $random->calls);
    }

    public function test_pve_evade_has_a_distinct_log_and_no_damage(): void
    {
        $random = $this->random([1, 1]);
        $resolver = $this->resolver($random, $this->evasion(100));
        $service = app(BattleService::class);
        $property = new \ReflectionProperty(BattleService::class, 'jobArtActionResolver');
        $property->setValue($service, $resolver);
        [$attacker, $defender, $state, $skill] = $this->battle('boss');

        $this->invoke($service, 'executeJobArtAction', [$attacker, $defender, $state, $skill]);

        $this->assertSame(5000, $defender->hp);
        $this->assertSame(100, $defender->def);
        $this->assertSame(110, $attacker->str);
        $this->assertSame(1, $state->jobArtUseCounts[$skill->id]);
        $this->assertArrayNotHasKey($skill->id, $state->jobArtCooldowns);
        $this->assertSame(2, $random->calls);
        $this->assertStringContainsString('試作複合奥義は回避された', implode("\n", $state->logs));
    }

    public function test_pvp_miss_uses_shared_resolution_and_suppresses_target_effects(): void
    {
        [$attacker, $defender, $state, $skill] = $this->battle('pvp');
        $skill->setAttribute('accuracy', 0);
        $service = new PvPBattleService(
            Mockery::mock(CharacterStatusService::class),
            new DamageCalculator(),
            $this->support($this->resolver($this->random([100]))),
        );

        $this->invoke($service, 'executeAction', [$attacker, $defender, $state]);

        $this->assertMissedCompositeResult($attacker, $defender, $state);
    }

    public function test_arena_npc_miss_uses_shared_resolution_and_suppresses_target_effects(): void
    {
        [$attacker, $defender, $state, $skill] = $this->battle('arena_npc');
        $skill->setAttribute('accuracy', 0);
        $service = new ArenaNpcBattleService(
            Mockery::mock(CharacterStatusService::class),
            new DamageCalculator(),
            Mockery::mock(ArenaNpcRankingService::class),
            $this->support($this->resolver($this->random([100]))),
        );

        $this->invoke($service, 'executeAction', [$attacker, $defender, $state]);

        $this->assertMissedCompositeResult($attacker, $defender, $state);
    }

    public function test_champ_miss_uses_shared_resolution_and_suppresses_target_effects(): void
    {
        [$attacker, $defender, $state, $skill] = $this->battle('champ');
        $skill->setAttribute('accuracy', 0);
        $service = new ChampBattleService(
            Mockery::mock(CharacterStatusService::class),
            new DamageCalculator(),
            Mockery::mock(LevelService::class),
            $this->support($this->resolver($this->random([100]))),
        );

        $result = $this->invoke($service, 'champAction', [$attacker, $defender, 100, 100, $state]);

        $this->assertSame(0, $result['damage']);
        $this->assertFalse($result['hit']);
        $this->assertMissedCompositeResult($attacker, $defender, $state, $result['log']);
    }

    public function test_tower_inherits_the_same_pve_job_art_execution_path(): void
    {
        $tower = file_get_contents(base_path('app/Services/TowerBattleService.php'));
        $battle = file_get_contents(base_path('app/Services/BattleService.php'));

        $this->assertStringContainsString('class TowerBattleService extends BattleService', $tower);
        $this->assertStringContainsString('$this->jobArtActionResolver->resolveJobArt(', $battle);
        $this->assertStringNotContainsString('resolveJobArt(', $tower);
    }

    public function test_normal_attack_and_current_job_special_never_consume_v2_hit_randomness(): void
    {
        $random = $this->random([1, 1]);
        $resolver = $this->resolver($random);
        $service = app(BattleService::class);
        $property = new \ReflectionProperty(BattleService::class, 'jobArtActionResolver');
        $property->setValue($service, $resolver);
        [$attacker, $defender, $state] = $this->battle('pve');
        $special = new Skill([
            'name' => '現在職必殺技',
            'skill_type' => 'active',
            'damage_type' => 'physical',
            'power_multiplier' => 1.0,
            'hit_count' => 1,
        ]);

        $this->invoke($service, 'executeNormalAttack', [$attacker, $defender, $state]);
        $this->invoke($service, 'executeSkillAction', [$attacker, $defender, $state, $special]);

        $this->assertSame(0, $random->calls);
    }

    public function test_hit_flag_off_keeps_job_art_on_legacy_rng_without_consuming_v2_hit_rng(): void
    {
        config(['battle.job_art_v2.hit_resolution' => false]);
        $random = $this->random([100]);
        $resolver = $this->resolver($random);
        $service = app(BattleService::class);
        $property = new \ReflectionProperty(BattleService::class, 'jobArtActionResolver');
        $property->setValue($service, $resolver);
        [$attacker, $defender, $state, $skill] = $this->battle('pve', 'MULTI_HIT');

        $this->invoke($service, 'executeJobArtAction', [$attacker, $defender, $state, $skill]);

        $this->assertSame(0, $random->calls);
        $this->assertStringNotContainsString('は外れた', implode("\n", $state->logs));
        $this->assertStringNotContainsString('は回避された', implode("\n", $state->logs));
    }

    private function assertMissedCompositeResult(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        ?string $log = null,
    ): void {
        $this->assertSame(94, $attacker->mp);
        $this->assertSame(110, $attacker->str);
        $this->assertSame(5000, $defender->hp);
        $this->assertSame(100, $defender->def);
        $this->assertSame(1, array_sum($state->jobArtUseCounts));
        $this->assertSame([], $state->jobArtCooldowns);
        $this->assertStringContainsString('試作複合奥義は外れた', $log ?? implode("\n", $state->logs));
    }

    private function support(ActionResolver $resolver): JobArtBattleSupportService
    {
        return new JobArtBattleSupportService(
            Mockery::mock(JobArtService::class),
            new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog()),
            app(JobArtV2SelectionService::class),
            app(JobArtV2SpCostCalculator::class),
            $resolver,
        );
    }

    private function resolver(
        JobArtV2HitRandomSource $random,
        ?JobArtV2ActiveEvasionProvider $evasion = null,
    ): ActionResolver {
        return new ActionResolver(
            new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog()),
            new DamageCalculator(),
            $random,
            $evasion ?? new JobArtV2ActiveEvasionProvider(),
        );
    }

    private function random(array $rolls): JobArtV2HitRandomSource
    {
        return new class($rolls) extends JobArtV2HitRandomSource
        {
            public int $calls = 0;

            public function __construct(private readonly array $rolls)
            {
            }

            public function percentRoll(): int
            {
                return $this->rolls[$this->calls++] ?? 100;
            }
        };
    }

    private function evasion(float $rate): JobArtV2ActiveEvasionProvider
    {
        return new class($rate) extends JobArtV2ActiveEvasionProvider
        {
            public function __construct(private readonly float $rate)
            {
            }

            public function rate(BattleActor $attacker, BattleActor $defender, Skill $skill, string $battleType): float
            {
                return $this->rate;
            }
        };
    }

    /** @return array{BattleActor, BattleActor, BattleState, Skill} */
    private function battle(string $battleType, string $template = 'DAMAGE_BUFF'): array
    {
        $skill = new Skill([
            'name' => '試作複合奥義',
            'job_id' => 24,
            'skill_type' => 'job_art',
            'learn_rank' => 1,
            'activation_rate' => 100,
            'sp_cost_fixed' => 10,
            'effect_template' => $template,
            'damage_type' => 'physical',
            'power' => 120,
            'power_multiplier' => 1.2,
            'hit_count' => 3,
            'cooldown_turns' => 2,
            'enemy_def_down_percent' => 25,
        ]);
        $skill->setAttribute('id', 9001);
        $attacker = new BattleActor('player', true, [
            'hp' => 1000,
            'max_hp' => 1000,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'current_job_id' => 24,
        ]);
        $attacker->jobArts = [$skill];
        $attacker->jobArtRates[$skill->id] = 1.0;
        $attacker->jobArtOrigins[$skill->id] = 'current';
        $attacker->jobArtPolicies[$skill->id] = 'aggressive';
        $attacker->jobArtActivationPolicy = 'aggressive';
        $defender = new BattleActor('enemy', false, [
            'hp' => 5000,
            'max_hp' => 5000,
            'def' => 100,
            'spr' => 100,
            'agi' => 100,
        ]);

        return [$attacker, $defender, new BattleState($attacker, $defender, $battleType), $skill];
    }

    private function invoke(object $service, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $arguments);
    }
}
