<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\BattleService;
use App\Services\CharacterStatusService;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtService;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2SpCostCalculator;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2SelectionResult;
use App\Services\JobArtV2SelectionService;
use Mockery;
use Tests\TestCase;

class JobArtDynamicSingleRoutingTest extends TestCase
{
    public function test_dynamic_single_is_disabled_by_default(): void
    {
        $config = require base_path('config/battle.php');

        $this->assertFalse($config['job_art_v2']['dynamic_single']);
    }

    public function test_target_current_job_uses_v2_in_pve_selector(): void
    {
        config(['battle.job_art_v2.dynamic_single' => true]);
        [$actor, $state, $skill] = $this->battle(53);
        $selector = Mockery::mock(JobArtV2SelectionService::class);
        $selector->shouldReceive('selectForTurn')
            ->once()
            ->with($actor, $state)
            ->andReturn($this->selectionResult($skill));

        $service = new class(
            Mockery::mock(CharacterStatusService::class),
            Mockery::mock(DamageCalculator::class),
            Mockery::mock(JobArtService::class),
            new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog()),
            $selector,
            app(JobArtV2SpCostCalculator::class),
        ) extends BattleService {
            public function selectForTest(BattleActor $actor, BattleState $state): ?Skill
            {
                return $this->selectJobArtForAction($actor, $state);
            }
        };

        $this->assertSame($skill, $service->selectForTest($actor, $state));
    }

    public function test_target_current_job_uses_v2_in_shared_interpersonal_selector(): void
    {
        config(['battle.job_art_v2.dynamic_single' => true]);
        [$actor, $state, $skill] = $this->battle(85);
        $selector = Mockery::mock(JobArtV2SelectionService::class);
        $selector->shouldReceive('selectForTurn')
            ->once()
            ->withArgs(fn (BattleActor $actualActor, BattleState $actualState, \Closure $key): bool =>
                $actualActor === $actor
                && $actualState === $state
                && $key($skill) === spl_object_id($actor) . ':' . $skill->id
            )
            ->andReturn($this->selectionResult($skill));
        $support = new JobArtBattleSupportService(
            Mockery::mock(JobArtService::class),
            new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog()),
            $selector,
            app(JobArtV2SpCostCalculator::class),
        );

        $this->assertSame($skill, $support->selectForTurn($actor, $state));
    }

    public function test_v2_miss_keeps_existing_special_skill_then_normal_attack_fallback_order(): void
    {
        config(['battle.job_art_v2.dynamic_single' => true]);
        [$actor, $state] = $this->battle(24);
        $selector = Mockery::mock(JobArtV2SelectionService::class);
        $selector->shouldReceive('selectForTurn')
            ->twice()
            ->andReturn(new JobArtV2SelectionResult(null, 9001, 50, false, false, false));
        $service = new class(
            Mockery::mock(CharacterStatusService::class),
            Mockery::mock(DamageCalculator::class),
            Mockery::mock(JobArtService::class),
            new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog()),
            $selector,
            app(JobArtV2SpCostCalculator::class),
        ) extends BattleService {
            public bool $specialUsed = false;
            public bool $normalUsed = false;

            public function act(BattleActor $actor, BattleActor $enemy, BattleState $state): void
            {
                $this->executeAction($actor, $enemy, $state);
            }

            protected function executeSkillAction(BattleActor $attacker, BattleActor $defender, BattleState $state, Skill $skill): void
            {
                $this->specialUsed = true;
            }

            protected function executeNormalAttack(BattleActor $attacker, BattleActor $defender, BattleState $state, int $powerMultiplier = 100): void
            {
                $this->normalUsed = true;
            }
        };
        $actor->skill = $this->specialSkill();

        $service->act($actor, $state->enemy, $state);

        $this->assertTrue($service->specialUsed);
        $this->assertFalse($service->normalUsed);

        [$normalActor, $normalState] = $this->battle(24);
        $service->specialUsed = false;
        $service->normalUsed = false;
        $service->act($normalActor, $normalState->enemy, $normalState);

        $this->assertFalse($service->specialUsed);
        $this->assertTrue($service->normalUsed);
    }

    public function test_all_six_battle_entry_paths_are_covered_without_moving_legacy_loops(): void
    {
        $battle = file_get_contents(base_path('app/Services/BattleService.php'));
        $tower = file_get_contents(base_path('app/Services/TowerBattleService.php'));
        $pvp = file_get_contents(base_path('app/Services/PvPBattleService.php'));
        $champ = file_get_contents(base_path('app/Services/ChampBattleService.php'));
        $arenaNpc = file_get_contents(base_path('app/Services/ArenaNpcBattleService.php'));

        $this->assertStringContainsString("\$battleContext = \$enemy->is_boss ? 'boss' : 'pve';", $battle);
        $this->assertStringContainsString('$this->selectJobArtForAction($attacker, $state)', $battle);
        $this->assertStringContainsString('class TowerBattleService extends BattleService', $tower);
        $this->assertStringContainsString("battleArtsFor(\$character, 'pve')", $tower);
        $this->assertStringContainsString('$this->jobArtBattleSupport->selectForTurn(', $pvp);
        $this->assertStringContainsString('$this->jobArtBattleSupport->selectForTurn(', $champ);
        $this->assertStringContainsString('$this->jobArtBattleSupport->selectForTurn(', $arenaNpc);
        $this->assertStringContainsString('private function selectJobArtForTurn(', $battle);
        $this->assertStringContainsString('foreach ($actor->jobArts as $art)', file_get_contents(base_path('app/Services/JobArtBattleSupportService.php')));
    }

    private function battle(int $currentJobId): array
    {
        $skill = new Skill([
            'name' => 'test-art',
            'skill_type' => 'job_art',
            'activation_rate' => 100,
            'effect_template' => 'DAMAGE',
        ]);
        $skill->setAttribute('id', 9001);
        $skill->setAttribute('job_id', 999); // routing depends on current job, not art origin
        $actor = new BattleActor('player', true, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 100,
            'max_mp' => 100,
            'current_job_id' => $currentJobId,
        ]);
        $actor->jobArts = [$skill];
        $enemy = new BattleActor('enemy', false, ['hp' => 100, 'max_hp' => 100]);

        return [$actor, new BattleState($actor, $enemy), $skill];
    }

    private function selectionResult(Skill $skill): JobArtV2SelectionResult
    {
        return new JobArtV2SelectionResult(
            skill: $skill,
            candidateSkillId: (int) $skill->id,
            activationRate: 100,
            activated: true,
            retriedAfterMiss: false,
            rankNinePrioritized: false,
        );
    }

    private function specialSkill(): Skill
    {
        $skill = new Skill([
            'name' => 'current-job-special',
            'skill_type' => 'active',
            'activation_rate' => 100,
            'sp_cost_fixed' => 0,
        ]);
        $skill->setAttribute('id', 9100);

        return $skill;
    }
}
