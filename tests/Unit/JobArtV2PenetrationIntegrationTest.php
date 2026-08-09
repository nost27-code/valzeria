<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\ActionResolver;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationRequest;
use App\Services\Battle\DamageApplicationResult;
use App\Services\Battle\DamageApplicationService;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\HitResult;
use App\Services\BattleService;
use App\Services\JobArtV2ActiveEvasionProvider;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2HitRandomSource;
use App\Services\JobArtV2PenetrationService;
use App\Services\JobArtV2PrototypeCatalog;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class JobArtV2PenetrationIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.penetration' => true,
            'battle.job_art_v2.fields' => false,
        ]);
    }

    public function test_hit_applies_formula_a_before_pve_damage_and_passes_the_final_value_to_damage_application(): void
    {
        $service = app(BattleService::class);
        $application = $this->damageSpy();
        $this->setProperty($service, BattleService::class, 'damageApplicationService', $application);
        $attacker = $this->actor('attacker', 62, 1000, 100, 100);
        $defender = $this->actor('defender', null, 100, 1000, 400, 100_000);
        $skill = $this->art(5);
        $attacker->jobArtOrigins[(int) $skill->id] = 'current';
        $state = new BattleState($attacker, $defender, 'pve');
        $penetration = app(JobArtV2PenetrationService::class);
        $overrideDef = $penetration->defenseOverrides($attacker, $defender, $skill)['def'];
        $calculator = new DamageCalculator;

        mt_srand(62105);
        $critical = $calculator->isCritical($attacker, $defender);
        $expected = $calculator->calculatePhysicalDamage($attacker, $defender, 285, $critical, null, $overrideDef);

        mt_srand(62105);
        $this->invoke($service, 'executeJobArtDamageTemplate', [
            $attacker,
            $defender,
            $state,
            $skill,
            285,
            'physical',
            true,
        ]);

        $this->assertCount(1, $application->requests);
        $this->assertSame($expected, $application->requests[0]->resolvedDamage);
        $this->assertSame(HitResult::HIT, $application->requests[0]->hitResult);
        $this->assertSame(1000, $defender->def);
        $this->assertSame(400, $defender->spr);
        $this->assertSame(100_000 - $expected, $defender->hp);
    }

    public function test_miss_and_evade_return_before_penetration_or_damage_calculation(): void
    {
        foreach ([HitResult::MISS, HitResult::EVADE] as $result) {
            $penetration = $this->penetrationSpy();
            $random = $this->random($result === HitResult::MISS ? [100] : [1]);
            $evasion = $result === HitResult::EVADE ? $this->evasion(100) : $this->evasion(0);
            $resolver = new ActionResolver(
                new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog),
                new DamageCalculator,
                $random,
                $evasion,
            );
            $service = app(BattleService::class);
            $this->setProperty($service, BattleService::class, 'jobArtV2PenetrationService', $penetration);
            $this->setProperty($service, BattleService::class, 'jobArtActionResolver', $resolver);
            $attacker = $this->actor('attacker', 62, 1000, 100, 100);
            $defender = $this->actor('defender', null, 100, 1000, 400, 100_000);
            $skill = $this->art(5);
            if ($result === HitResult::MISS) {
                $skill->setAttribute('accuracy', 0);
            } else {
                $skill->setAttribute('sure_hit', true);
            }
            $attacker->jobArtOrigins[(int) $skill->id] = 'current';
            $state = new BattleState($attacker, $defender, 'pve');

            $this->invoke($service, 'executeJobArtAction', [$attacker, $defender, $state, $skill]);

            $this->assertSame(0, $penetration->calls, $result->value);
            $this->assertSame(100_000, $defender->hp, $result->value);
            $this->assertStringContainsString(
                $result === HitResult::MISS ? '外れた' : '回避された',
                implode("\n", $state->logs),
            );
        }
    }

    public function test_flag_off_keeps_legacy_def_and_spr_override_damage_and_rng_sequence(): void
    {
        config(['battle.job_art_v2.penetration' => false]);
        $attacker = $this->actor('attacker', 62, 1000, 100, 100);
        $defender = $this->actor('defender', null, 100, 1000, 400, 100_000);
        $skill = $this->art(5, 20);
        $attacker->jobArtOrigins[(int) $skill->id] = 'current';
        $service = app(JobArtV2PenetrationService::class);
        $calculator = new DamageCalculator;
        $overrides = $service->defenseOverrides($attacker, $defender, $skill);

        mt_srand(62106);
        $expectedDamage = $calculator->calculatePhysicalDamage($attacker, $defender, 285, false, null, 800);
        $expectedNext = mt_rand();
        mt_srand(62106);
        $actualDamage = $calculator->calculatePhysicalDamage($attacker, $defender, 285, false, null, $overrides['def']);
        $actualNext = mt_rand();

        $this->assertSame(800, $overrides['def']);
        $this->assertSame(320, $overrides['spr']);
        $this->assertSame($expectedDamage, $actualDamage);
        $this->assertSame($expectedNext, $actualNext);
    }

    public function test_all_six_paths_use_the_two_existing_shared_execution_points(): void
    {
        $battle = file_get_contents(base_path('app/Services/BattleService.php'));
        $tower = file_get_contents(base_path('app/Services/TowerBattleService.php'));
        $pvp = file_get_contents(base_path('app/Services/PvPBattleService.php'));
        $champ = file_get_contents(base_path('app/Services/ChampBattleService.php'));
        $arenaNpc = file_get_contents(base_path('app/Services/ArenaNpcBattleService.php'));

        $this->assertStringContainsString('$this->jobArtV2PenetrationService->defenseOverrides(', $battle);
        $this->assertStringContainsString('class TowerBattleService extends BattleService', $tower);
        $this->assertStringNotContainsString('defenseOverrides(', $tower);
        foreach ([$pvp, $champ, $arenaNpc] as $source) {
            $this->assertStringContainsString('$this->jobArtBattleSupport->defenseOverrides(', $source);
        }
    }

    private function penetrationSpy(): JobArtV2PenetrationService
    {
        return new class(new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog), new JobArtV2PrototypeCatalog) extends JobArtV2PenetrationService
        {
            public int $calls = 0;

            public function defenseOverrides(BattleActor $attacker, BattleActor $defender, Skill $skill): array
            {
                $this->calls++;

                return parent::defenseOverrides($attacker, $defender, $skill);
            }
        };
    }

    private function random(array $rolls): JobArtV2HitRandomSource
    {
        return new class($rolls) extends JobArtV2HitRandomSource
        {
            public int $calls = 0;

            public function __construct(private readonly array $rolls) {}

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
            public function __construct(private readonly float $rate) {}

            public function rate(BattleActor $attacker, BattleActor $defender, Skill $skill, string $battleType): float
            {
                return $this->rate;
            }
        };
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

    private function art(int $rank, int $ignore = 0): Skill
    {
        $skill = new Skill([
            'name' => "job-62-rank-{$rank}",
            'skill_type' => 'job_art',
            'job_id' => 62,
            'learn_rank' => $rank,
            'activation_rate' => 100,
            'effect_template' => 'PHYSICAL_DAMAGE',
            'damage_type' => 'physical',
            'power' => 285,
            'power_multiplier' => 2.85,
            'hit_count' => 1,
            'def_ignore_percent' => $ignore,
        ]);
        $skill->setAttribute('id', 62_000 + $rank + $ignore);

        return $skill;
    }

    private function actor(
        string $name,
        ?int $jobId,
        int $str,
        int $def,
        int $spr,
        int $hp = 10_000,
    ): BattleActor {
        return new BattleActor($name, true, [
            'hp' => $hp,
            'max_hp' => $hp,
            'mp' => 400,
            'max_mp' => 400,
            'str' => $str,
            'def' => $def,
            'agi' => 100,
            'mag' => 100,
            'spr' => $spr,
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
