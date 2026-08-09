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
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2SelectionService;
use App\Services\JobArtV2SpCostCalculator;
use Mockery;
use Tests\TestCase;

class JobArtLegacySelectionCharacterizationTest extends TestCase
{
    public function test_pve_legacy_selection_retries_later_art_after_front_art_misses_with_seeded_rng(): void
    {
        $selection = Mockery::mock(JobArtV2SelectionService::class);
        $selection->shouldNotReceive('selectForTurn');
        $service = new class(
            Mockery::mock(CharacterStatusService::class),
            Mockery::mock(DamageCalculator::class),
            Mockery::mock(JobArtService::class),
            new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog()),
            $selection,
            app(JobArtV2SpCostCalculator::class),
        ) extends BattleService {
            public function selectForTest(BattleActor $actor, BattleState $state): ?Skill
            {
                return $this->selectJobArtForAction($actor, $state);
            }
        };
        [$actor, $state] = $this->battleWithArts([
            $this->art(101, 75),
            $this->art(102, 100),
        ]);
        $actor->currentJobId = 24;
        config(['battle.job_art_v2.dynamic_single' => false]);

        srand(1234); // legacy rand sequence begins 76, 72
        $selected = $service->selectForTest($actor, $state);

        $this->assertSame(102, $selected?->id);
    }

    public function test_interpersonal_legacy_selection_retries_later_art_after_front_art_misses(): void
    {
        $selection = Mockery::mock(JobArtV2SelectionService::class);
        $selection->shouldNotReceive('selectForTurn');
        $support = new JobArtBattleSupportService(
            Mockery::mock(JobArtService::class),
            new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog()),
            $selection,
            app(JobArtV2SpCostCalculator::class),
        );
        [$actor, $state] = $this->battleWithArts([
            $this->art(201, 0),
            $this->art(202, 100),
        ]);
        $actor->currentJobId = 10;
        config(['battle.job_art_v2.dynamic_single' => true]);

        $selected = $support->selectForTurn($actor, $state);

        $this->assertSame(202, $selected?->id);
    }

    public function test_legacy_sp_cost_and_conserve_sixty_percent_boundary_are_unchanged(): void
    {
        $selection = Mockery::mock(JobArtV2SelectionService::class);
        $selection->shouldNotReceive('selectForTurn');
        $support = new JobArtBattleSupportService(
            Mockery::mock(JobArtService::class),
            new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog()),
            $selection,
            app(JobArtV2SpCostCalculator::class),
        );
        $art = $this->art(301, 100);
        $art->sp_cost_fixed = 10;
        [$actor, $state] = $this->battleWithArts([$art]);
        $actor->currentJobId = 24;
        $actor->jobArtActivationPolicy = 'conserve';
        $actor->jobArtOrigins[301] = 'current';
        config([
            'battle.job_art_v2.dynamic_single' => false,
            'battle.job_art_v2.normalized_sp' => false,
        ]);

        $this->assertSame(8, $support->spCost($actor, $art));

        $actor->mp = 59;
        $this->assertNull($support->selectForTurn($actor, $state));

        $actor->mp = 60;
        $this->assertSame(301, $support->selectForTurn($actor, $state)?->id);
    }

    /** @param array<int, Skill> $arts */
    private function battleWithArts(array $arts): array
    {
        $actor = new BattleActor('player', true, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 100,
            'max_mp' => 100,
        ]);
        $actor->jobArts = $arts;
        $actor->jobArtActivationPolicy = 'aggressive';
        $enemy = new BattleActor('enemy', false, ['hp' => 100, 'max_hp' => 100]);

        return [$actor, new BattleState($actor, $enemy)];
    }

    private function art(int $id, int $activationRate): Skill
    {
        $skill = new Skill([
            'name' => "art-{$id}",
            'skill_type' => 'job_art',
            'activation_rate' => $activationRate,
            'sp_cost_rate' => 0,
            'effect_template' => 'DAMAGE',
        ]);
        $skill->setAttribute('id', $id);

        return $skill;
    }
}
