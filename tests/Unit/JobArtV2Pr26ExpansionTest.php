<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\FieldState;
use App\Services\JobArtLineageCatalog;
use App\Services\JobArtV2BattleRules;
use App\Services\JobArtV2FinisherConditionProvider;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2RandomSource;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2SelectionService;
use App\Services\JobArtV2SlotConditionCatalog;
use App\Services\JobArtV2SpCostCalculator;
use Tests\TestCase;

class JobArtV2Pr26ExpansionTest extends TestCase
{
    private const ADVANCED = [27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 44, 45, 46, 47, 48, 49];

    private const SUPER = [50, 51, 52, 53, 54, 55, 56, 57, 58, 59];

    private const EXISTING = [24, 53, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 85];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
            'battle.job_art_v2.penetration' => true,
            'battle.job_art_v2.penetration_stance' => true,
        ]);
    }

    public function test_all_ninety_four_master_jobs_resolve_by_id_with_no_unknown_actual_job(): void
    {
        $masterIds = [];
        $handle = fopen(base_path('jobs_data.tsv'), 'rb');
        $this->assertNotFalse($handle);
        fgetcsv($handle, separator: "\t");
        while (($row = fgetcsv($handle, separator: "\t")) !== false) {
            if (isset($row[0]) && ctype_digit((string) $row[0])) {
                $masterIds[] = (int) $row[0];
            }
        }
        fclose($handle);

        $catalog = app(JobArtLineageCatalog::class);
        $mappedIds = array_keys($catalog->mappedJobs());
        sort($masterIds);
        sort($mappedIds);

        $this->assertCount(94, $masterIds);
        $this->assertSame($masterIds, $mappedIds);
        foreach ($masterIds as $jobId) {
            $this->assertContains($catalog->forJob($jobId)['lineage_key'], [
                'field', 'counter', 'eclipse', 'pierce', 'hunt',
                'aim', 'guard', 'transmute', 'break', 'command',
            ]);
        }
        $this->assertNull($catalog->forJob(39));
        $this->assertNull($catalog->forJob(100));
    }

    public function test_advanced_super_and_existing_current_jobs_are_enabled_without_enabling_unimplemented_high_tiers(): void
    {
        $catalog = app(JobArtV2PrototypeCatalog::class);

        foreach (self::ADVANCED as $jobId) {
            $this->assertSame('advanced', $catalog->currentJobTier($jobId), (string) $jobId);
        }
        foreach (self::SUPER as $jobId) {
            $this->assertSame('super', $catalog->currentJobTier($jobId), (string) $jobId);
        }
        foreach (self::EXISTING as $jobId) {
            $this->assertTrue($catalog->supportsCurrentJob($jobId), (string) $jobId);
        }
        foreach ([70, 84, 86, 94] as $jobId) {
            $this->assertFalse($catalog->supportsCurrentJob($jobId), (string) $jobId);
        }

        $this->assertCount(40, $catalog->supportedCurrentJobs());
        foreach (array_unique([...self::ADVANCED, ...self::SUPER]) as $jobId) {
            foreach ([1 => 'producer', 5 => 'consumer', 9 => 'finisher'] as $rank => $role) {
                $skill = $this->art($jobId, $rank);
                $this->assertTrue($catalog->isTrustedCurrentJobArt($jobId, $skill));
                $expectedRole = $jobId === 46 && $rank === 5 ? 'neutral' : $role;
                $this->assertSame($expectedRole, $catalog->artResourceMetadata($skill)['resource_role']);
            }
        }

        $coverage = collect(array_unique([...self::ADVANCED, ...self::SUPER]))
            ->countBy(fn (int $jobId): string => (string) $catalog->effectCoverageForCurrentJob($jobId));
        $this->assertSame(4, $coverage->get('full_v2_effect'));
        $this->assertSame(24, $coverage->get('resource_v2_master_effect_fallback'));
    }

    public function test_same_lineage_inherited_producer_consumer_and_finisher_share_one_current_resource(): void
    {
        [$actor, $state] = $this->battle(53);
        $producer = $this->art(24, 1);
        $consumer = $this->art(24, 5);
        $finisher = $this->art(24, 9);
        foreach ([$producer, $consumer, $finisher] as $skill) {
            $actor->jobArtOrigins[(int) $skill->id] = 'inherited';
        }
        $actor->configureResource('star_mark', 12);
        $resources = app(JobArtV2ResourceService::class);

        $resources->beginAction($actor, $state);
        $this->assertSame(4, $resources->applyJobArtCast($actor, $state, $producer)->delta);
        $this->assertSame(4, $actor->getResource('star_mark'));

        $resources->beginAction($actor, $state);
        $this->assertSame(-4, $resources->applyJobArtCast($actor, $state, $consumer)->delta);
        $this->assertSame(0, $actor->getResource('star_mark'));

        $actor->setResource('star_mark', 12);
        $this->assertTrue($resources->isFinisherReady($actor, $finisher));
        $resources->beginAction($actor, $state);
        $this->assertSame(-12, $resources->applyJobArtCast($actor, $state, $finisher)->delta);
        $this->assertSame(0, $actor->getResource('star_mark'));
        $this->assertSame(0, $actor->getResource('dragon_force'));
    }

    public function test_current_finisher_precedes_same_lineage_finisher_which_precedes_no_cross_lineage_finisher(): void
    {
        [$actor, $state] = $this->battle(53);
        $inherited = $this->art(24, 9);
        $current = $this->art(53, 9);
        $actor->jobArts = [$inherited, $current];
        $actor->jobArtOrigins[(int) $inherited->id] = 'inherited';
        $actor->jobArtOrigins[(int) $current->id] = 'current';
        $actor->configureResource('star_mark', 12);
        $actor->setResource('star_mark', 12);

        $first = $this->selection([1])->selectForTurn($actor, $state);
        $this->assertSame((int) $current->id, (int) $first->skill?->id);
        $this->assertTrue($first->rankNinePrioritized);

        $state->jobArtUseCounts[(int) $current->id] = 1;
        $second = $this->selection([1])->selectForTurn($actor, $state);
        $this->assertSame((int) $inherited->id, (int) $second->skill?->id);
        $this->assertTrue($second->rankNinePrioritized);

        $cross = $this->art(62, 9);
        $front = $this->art(62, 1);
        $actor->jobArts = [$front, $cross];
        $actor->jobArtOrigins[(int) $front->id] = 'inherited';
        $actor->jobArtOrigins[(int) $cross->id] = 'inherited';
        $third = $this->selection([1])->selectForTurn($actor, $state);
        $this->assertSame((int) $front->id, (int) $third->skill?->id);
        $this->assertFalse($third->rankNinePrioritized);
    }

    public function test_inherited_active_arts_use_v2_activation_and_normalized_sp_without_discount(): void
    {
        $rules = app(JobArtV2BattleRules::class);
        $sp = app(JobArtV2SpCostCalculator::class);

        foreach ([1 => [35, 8, 10], 5 => [38, 13, 16], 9 => [50, 18, 22]] as $rank => [$rate, $currentCost, $inheritedCost]) {
            $current = $this->art(53, $rank, 87, 10);
            $inherited = $this->art(24, $rank, 87, 10);
            $this->assertSame($rate, $rules->activationRateFor($inherited, 53, 'inherited'));
            $this->assertSame($currentCost, $sp->forCurrentJob($current, 400, 53, 'current'));
            $this->assertSame($inheritedCost, $sp->forCurrentJob($inherited, 400, 53, 'inherited'));
            $this->assertSame(87, $inherited->effectiveActivationRate());
        }

        config(['battle.job_art_v2.dynamic_single' => false]);
        $legacy = $this->art(24, 9, 87, 10);
        $this->assertSame(87, $rules->activationRateFor($legacy, 53, 'inherited'));
        $this->assertSame(10, $sp->forCurrentJob($legacy, 400, 53, 'inherited'));
    }

    public function test_all_mvp_slot_conditions_are_deterministic_and_match_boundaries(): void
    {
        [$actor, $state] = $this->battle(53, actorHp: 50, targetHp: 30, targetDef: 600, targetSpr: 400);
        $actor->configureResource('star_mark', 12);
        $catalog = app(JobArtV2SlotConditionCatalog::class);

        $this->assertSame([
            'always', 'self_hp_le_50', 'target_hp_le_50', 'target_hp_le_30',
            'main_resource_lt_4', 'main_resource_ge_4', 'target_def_gt_spr',
            'target_spr_gt_def', 'field_present',
        ], array_keys($catalog->labels()));
        $this->assertTrue($catalog->matches('self_hp_le_50', $actor, $state));
        $this->assertTrue($catalog->matches('target_hp_le_50', $actor, $state));
        $this->assertTrue($catalog->matches('target_hp_le_30', $actor, $state));
        $this->assertTrue($catalog->matches('main_resource_lt_4', $actor, $state));
        $this->assertFalse($catalog->matches('main_resource_ge_4', $actor, $state));
        $this->assertTrue($catalog->matches('target_def_gt_spr', $actor, $state));
        $this->assertFalse($catalog->matches('target_spr_gt_def', $actor, $state));
        $this->assertFalse($catalog->matches('field_present', $actor, $state));

        $actor->setResource('star_mark', 4);
        $state->replacePrimaryField(new FieldState('star_light', 'player', 2, 1, 1, 1));
        $before = serialize([$actor, $state]);
        $this->assertTrue($catalog->matches('main_resource_ge_4', $actor, $state));
        $this->assertTrue($catalog->matches('field_present', $actor, $state));
        $this->assertSame($before, serialize([$actor, $state]));
        $this->assertSame('always', $catalog->normalize('not_registered'));
    }

    public function test_false_front_condition_reaches_rear_but_true_front_and_activation_miss_never_retry(): void
    {
        [$actor, $state] = $this->battle(53, targetHp: 80);
        $front = $this->art(62, 1, 100);
        $rear = $this->art(24, 1, 100);
        $actor->jobArts = [$front, $rear];
        $actor->jobArtOrigins[(int) $front->id] = 'inherited';
        $actor->jobArtOrigins[(int) $rear->id] = 'inherited';
        $actor->jobArtConditions[(int) $front->id] = 'target_hp_le_30';
        $actor->jobArtConditions[(int) $rear->id] = 'always';

        $random = $this->random([1]);
        $result = $this->selectionWithRandom($random)->selectForTurn($actor, $state);
        $this->assertSame((int) $rear->id, (int) $result->skill?->id);
        $this->assertSame('blocked_by_condition', $result->blockedReasons[(int) $front->id]);
        $this->assertSame(1, $random->calls);

        $state->enemy->hp = 30;
        $random = $this->random([1]);
        $result = $this->selectionWithRandom($random)->selectForTurn($actor, $state);
        $this->assertSame((int) $front->id, (int) $result->skill?->id);
        $this->assertSame(1, $random->calls);

        $random = $this->random([100, 1]);
        $result = $this->selectionWithRandom($random)->selectForTurn($actor, $state);
        $this->assertNull($result->skill);
        $this->assertSame((int) $front->id, $result->candidateSkillId);
        $this->assertFalse($result->retriedAfterMiss);
        $this->assertSame(1, $random->calls);
    }

    public function test_slot_conditions_use_database_columns_and_no_cache_store(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_08_09_120000_add_condition_key_to_job_art_slots.php'));
        $loadout = file_get_contents(base_path('app/Services/JobArtService.php'));
        $preset = file_get_contents(base_path('app/Services/JobArtPresetService.php'));

        $this->assertStringContainsString("string('condition_key', 40)->default('always')", $migration);
        $this->assertStringContainsString("\$payload['condition_key'] = \$conditions[\$slotNo]", $loadout);
        $this->assertStringContainsString("'condition_key' => \$this->slotConditionCatalog->normalize", $preset);
        $this->assertFileDoesNotExist(base_path('app/Services/JobArtV2SlotConditionStore.php'));
    }

    public function test_all_six_combat_routes_forward_loaded_slot_conditions(): void
    {
        $battle = file_get_contents(base_path('app/Services/BattleService.php'));
        $tower = file_get_contents(base_path('app/Services/TowerBattleService.php'));
        $support = file_get_contents(base_path('app/Services/JobArtBattleSupportService.php'));

        $this->assertStringContainsString('jobArtConditions', $battle); // PvE / boss
        $this->assertStringContainsString('jobArtConditions', $tower); // tower
        $this->assertStringContainsString('jobArtConditions', $support); // PvP / champ / NPC arena
        foreach (['PvPBattleService.php', 'ChampBattleService.php', 'ArenaNpcBattleService.php'] as $file) {
            $source = file_get_contents(base_path('app/Services/'.$file));
            $this->assertStringContainsString('attachBossSet(', $source, $file);
        }
    }

    /** @return array{BattleActor, BattleState} */
    private function battle(
        int $currentJobId,
        int $actorHp = 100,
        int $targetHp = 100,
        int $targetDef = 100,
        int $targetSpr = 100,
    ): array {
        $actor = new BattleActor('player', true, [
            'hp' => $actorHp, 'max_hp' => 100, 'mp' => 400, 'max_mp' => 400,
            'def' => 100, 'spr' => 100, 'current_job_id' => $currentJobId,
        ]);
        $actor->jobArtActivationPolicy = 'aggressive';
        $target = new BattleActor('enemy', false, [
            'hp' => $targetHp, 'max_hp' => 100, 'mp' => 400, 'max_mp' => 400,
            'def' => $targetDef, 'spr' => $targetSpr,
        ]);

        return [$actor, new BattleState($actor, $target)];
    }

    private function art(int $jobId, int $rank, int $activationRate = 100, ?int $spCostFixed = null): Skill
    {
        $skill = new Skill([
            'name' => "job-{$jobId}-rank-{$rank}",
            'job_id' => $jobId,
            'skill_type' => 'job_art',
            'learn_rank' => $rank,
            'activation_rate' => $activationRate,
            'sp_cost_fixed' => $spCostFixed,
            'effect_template' => 'PHYSICAL_DAMAGE',
            'max_uses_per_battle' => $rank === 9 ? 1 : null,
        ]);
        $skill->setAttribute('id', ($jobId * 100) + $rank);

        return $skill;
    }

    private function selection(array $rolls): JobArtV2SelectionService
    {
        return $this->selectionWithRandom($this->random($rolls));
    }

    private function selectionWithRandom(JobArtV2RandomSource $random): JobArtV2SelectionService
    {
        return new JobArtV2SelectionService(
            $random,
            app(JobArtV2FinisherConditionProvider::class),
            app(JobArtV2SpCostCalculator::class),
            app(JobArtV2BattleRules::class),
        );
    }

    private function random(array $rolls): JobArtV2RandomSource
    {
        return new class($rolls) extends JobArtV2RandomSource
        {
            public int $calls = 0;

            public function __construct(private array $rolls) {}

            public function percentRoll(): int
            {
                return (int) ($this->rolls[$this->calls++] ?? 100);
            }
        };
    }
}
