<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\ActionResolver;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\HitResult;
use App\Services\BattleService;
use App\Services\JobArtV2ActiveEvasionProvider;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2HitRandomSource;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2ResourceService;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class JobArtV2PenetrationStanceIntegrationTest extends TestCase
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
            'battle.job_art_v2.penetration_stance' => true,
            'battle.job_art_v2.fields' => false,
        ]);
    }

    public function test_pve_rank_five_uses_the_start_snapshot_and_reforms_after_damage(): void
    {
        [$withoutDamage, $withoutActor, $withoutState] = $this->executePveRankFive(false);
        [$withDamage, $withActor, $withState] = $this->executePveRankFive(true);

        $this->assertGreaterThan($withoutDamage, $withDamage);
        $this->assertTrue($withoutActor->hasPiercingStance());
        $this->assertTrue($withActor->hasPiercingStance());
        $this->assertSame(['no_stance', 'reformed'], array_column($withoutState->piercingStanceEvents(), 'event'));
        $this->assertSame(['consumed', 'reformed'], array_column($withState->piercingStanceEvents(), 'event'));
        $this->assertStringContainsString('構えなしのため貫通なし', implode("\n", $withoutState->logs));
        $this->assertStringContainsString('貫通構えを再形成した', implode("\n", $withState->logs));
    }

    public function test_rank_five_reforms_and_rank_nine_stays_consumed_after_miss_and_evade(): void
    {
        foreach ([HitResult::MISS, HitResult::EVADE] as $result) {
            foreach ([5, 9] as $rank) {
                $actor = $this->actor('attacker', 62, 1000, 100);
                $actor->setPiercingStance(true);
                $actor->configureResource('dragon_force', 12);
                $actor->setResource('dragon_force', $rank === 5 ? 4 : 12);
                $defender = $this->actor('defender', null, 100, 1000);
                $skill = $this->art($rank);
                $actor->jobArtOrigins[(int) $skill->id] = 'current';
                if ($result === HitResult::MISS) {
                    $skill->setAttribute('accuracy', 0);
                } else {
                    $skill->setAttribute('sure_hit', true);
                }
                $state = new BattleState($actor, $defender, 'pve');
                app(JobArtV2ResourceService::class)->beginAction($actor, $state);
                $service = app(BattleService::class);
                $this->setProperty(
                    $service,
                    BattleService::class,
                    'jobArtActionResolver',
                    $this->resolver($result),
                );

                $this->invoke($service, 'executeJobArtAction', [$actor, $defender, $state, $skill]);

                $this->assertSame(100_000, $defender->hp, "rank {$rank} {$result->value}");
                $this->assertSame($rank === 5, $actor->hasPiercingStance(), "rank {$rank} {$result->value}");
                $this->assertSame(0, $actor->getResource('dragon_force'), "rank {$rank} {$result->value}");
                $this->assertContains('consumed', array_column($state->piercingStanceEvents(), 'event'));
                if ($rank === 5) {
                    $this->assertContains('reformed', array_column($state->piercingStanceEvents(), 'event'));
                } else {
                    $this->assertNotContains('reformed', array_column($state->piercingStanceEvents(), 'event'));
                }
            }
        }
    }

    public function test_six_battle_paths_share_the_stance_snapshot_and_completion_hooks(): void
    {
        $battle = file_get_contents(base_path('app/Services/BattleService.php'));
        $tower = file_get_contents(base_path('app/Services/TowerBattleService.php'));
        $pvp = file_get_contents(base_path('app/Services/PvPBattleService.php'));
        $champ = file_get_contents(base_path('app/Services/ChampBattleService.php'));
        $arenaNpc = file_get_contents(base_path('app/Services/ArenaNpcBattleService.php'));

        $this->assertStringContainsString('$this->jobArtV2PenetrationStanceService->beginCast(', $battle);
        $this->assertStringContainsString('$this->jobArtV2PenetrationStanceService->completeCast(', $battle);
        $this->assertStringContainsString('$this->jobArtDefenseOverrides(', $battle);
        $this->assertStringContainsString('class TowerBattleService extends BattleService', $tower);

        foreach ([$pvp, $champ, $arenaNpc] as $source) {
            $this->assertStringContainsString('$this->jobArtBattleSupport->completeJobArtCast(', $source);
            $this->assertStringContainsString('$this->jobArtBattleSupport->defenseOverrides($attacker, $defender, $state, $skill)', $source);
        }
    }

    public function test_stance_changes_only_the_defense_input_and_not_power_accuracy_or_actor_stats(): void
    {
        $actor = $this->actor('attacker', 62, 1000, 100);
        $defender = $this->actor('defender', null, 100, 1000);
        $skill = $this->art(5);
        $actor->jobArtOrigins[(int) $skill->id] = 'current';
        $actor->setPiercingStance(true);
        $state = new BattleState($actor, $defender, 'pve');
        $state->beginSourceAction();
        $stance = app(\App\Services\JobArtV2PenetrationStanceService::class);

        $before = [$actor->str, $actor->def, $actor->agi, $actor->mag, $actor->spr, $actor->luk, $skill->power, $skill->accuracy];
        $stance->beginCast($actor, $state, $skill);
        $overrides = $stance->defenseOverrides($actor, $defender, $state, $skill);
        $after = [$actor->str, $actor->def, $actor->agi, $actor->mag, $actor->spr, $actor->luk, $skill->power, $skill->accuracy];

        $this->assertSame($before, $after);
        $this->assertSame(650, $overrides['def']);
        $this->assertNull($overrides['spr']);
        $this->assertSame(1000, $defender->def);
        $this->assertSame(100, $defender->spr);
    }

    /** @return array{int, BattleActor, BattleState} */
    private function executePveRankFive(bool $withStance): array
    {
        $actor = $this->actor('attacker', 62, 1000, 100);
        $actor->setPiercingStance($withStance);
        $actor->configureResource('dragon_force', 12);
        $actor->setResource('dragon_force', 4);
        $defender = $this->actor('defender', null, 100, 2000);
        $skill = $this->art(5);
        $skill->setAttribute('sure_hit', true);
        $actor->jobArtOrigins[(int) $skill->id] = 'current';
        $state = new BattleState($actor, $defender, 'pve');
        app(JobArtV2ResourceService::class)->beginAction($actor, $state);

        mt_srand(62115);
        $this->invoke(app(BattleService::class), 'executeJobArtAction', [$actor, $defender, $state, $skill]);

        return [100_000 - $defender->hp, $actor, $state];
    }

    private function resolver(HitResult $result): ActionResolver
    {
        $random = new class($result) extends JobArtV2HitRandomSource
        {
            public function __construct(private readonly HitResult $result)
            {
            }

            public function percentRoll(): int
            {
                return $this->result === HitResult::MISS ? 100 : 1;
            }
        };
        $evasion = new class($result) extends JobArtV2ActiveEvasionProvider
        {
            public function __construct(private readonly HitResult $result)
            {
            }

            public function rate(BattleActor $attacker, BattleActor $defender, Skill $skill, string $battleType): float
            {
                return $this->result === HitResult::EVADE ? 100 : 0;
            }
        };

        return new ActionResolver(
            new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog()),
            new DamageCalculator(),
            $random,
            $evasion,
        );
    }

    private function art(int $rank): Skill
    {
        $skill = new Skill([
            'name' => "job-62-rank-{$rank}",
            'skill_type' => 'job_art',
            'job_id' => 62,
            'learn_rank' => $rank,
            'activation_rate' => 100,
            'effect_template' => 'PHYSICAL_DAMAGE',
            'damage_type' => 'physical',
            'power' => $rank === 5 ? 285 : 355,
            'power_multiplier' => $rank === 5 ? 2.85 : 3.55,
            'hit_count' => 1,
            'def_ignore_percent' => 0,
            'cooldown_turns' => $rank === 5 ? 2 : 5,
            'max_uses_per_battle' => $rank === 9 ? 1 : null,
        ]);
        $skill->setAttribute('id', 62_000 + $rank);

        return $skill;
    }

    private function actor(string $name, ?int $jobId, int $str, int $def): BattleActor
    {
        return new BattleActor($name, true, [
            'hp' => 100_000,
            'max_hp' => 100_000,
            'mp' => 400,
            'max_mp' => 400,
            'str' => $str,
            'def' => $def,
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
