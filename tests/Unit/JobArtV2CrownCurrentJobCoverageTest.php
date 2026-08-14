<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\JobArtService;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtLineageCatalog;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2SpCostCalculator;
use Tests\TestCase;

class JobArtV2CrownCurrentJobCoverageTest extends TestCase
{
    public function test_current_job_support_is_exactly_all_ninety_four_master_jobs(): void
    {
        $catalog = app(JobArtV2PrototypeCatalog::class);
        $expected = $this->allJobs();

        $this->assertSame($expected, array_keys($catalog->supportedCurrentJobs()));
        $this->assertSame($expected, array_keys(app(JobArtLineageCatalog::class)->mappedJobs()));
        $this->assertCount(94, $catalog->supportedCurrentJobs());

        foreach (range(1, 8) as $jobId) {
            $this->assertSame('basic', $catalog->currentJobTier($jobId), (string) $jobId);
        }
        foreach (range(9, 26) as $jobId) {
            $this->assertSame('intermediate', $catalog->currentJobTier($jobId), (string) $jobId);
        }
        foreach ([...range(27, 38), ...range(44, 49)] as $jobId) {
            $this->assertSame('advanced', $catalog->currentJobTier($jobId), (string) $jobId);
        }
        foreach (range(50, 59) as $jobId) {
            $this->assertSame('super', $catalog->currentJobTier($jobId), (string) $jobId);
        }
        foreach (range(60, 69) as $jobId) {
            $this->assertSame('crown', $catalog->currentJobTier($jobId), (string) $jobId);
        }
        foreach (range(70, 79) as $jobId) {
            $this->assertSame('hero', $catalog->currentJobTier($jobId), (string) $jobId);
        }
        foreach ([...range(80, 84), ...range(95, 99)] as $jobId) {
            $this->assertSame('legend', $catalog->currentJobTier($jobId), (string) $jobId);
        }
        foreach (range(85, 94) as $jobId) {
            $this->assertSame('myth', $catalog->currentJobTier($jobId), (string) $jobId);
        }
        foreach ([39, 40, 41, 42, 43, 100] as $jobId) {
            $this->assertFalse($catalog->supportsCurrentJob($jobId), (string) $jobId);
        }
    }

    public function test_all_282_master_profiles_are_trusted_current_arts(): void
    {
        $rows = json_decode(
            (string) file_get_contents(base_path('database/data/job_arts.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertCount(282, $rows);

        $catalog = app(JobArtV2PrototypeCatalog::class);
        $expectedJobs = array_fill_keys($this->allJobs(), true);
        $seen = [];
        foreach ($rows as $row) {
            $jobId = (int) $row['job_id'];
            $rank = (int) $row['learn_rank'];
            $key = "{$jobId}:{$rank}";
            $this->assertArrayHasKey($jobId, $expectedJobs, $key);
            $this->assertContains($rank, [1, 5, 9], $key);
            $this->assertArrayNotHasKey($key, $seen, $key);
            $seen[$key] = true;

            $skill = new Skill($row);
            $this->assertTrue($catalog->isTrustedCurrentJobArt($jobId, $skill), $key);
            $this->assertSame(
                $catalog->currentJobTier($jobId),
                $catalog->jobResourceMetadata($jobId)['tier'] ?? null,
                $key,
            );
            $this->assertSame(
                $catalog->effectCoverageForCurrentJob($jobId),
                $catalog->artResourceMetadata($skill)['effect_coverage'] ?? null,
                $key,
            );
        }

        $this->assertCount(282, $seen);
    }

    public function test_all_282_v2_arts_use_stage_costs_even_outside_the_current_job(): void
    {
        config(['battle.job_art_v2.loadout_v2' => true]);
        $rows = json_decode(
            (string) file_get_contents(base_path('database/data/job_arts.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $character = new Character(['current_job_id' => 1]);
        $service = app(JobArtService::class);

        foreach ($rows as $row) {
            $skill = new Skill($row);
            $expected = match ((int) $row['learn_rank']) {
                1 => 1,
                5 => 2,
                9 => 3,
            };

            $this->assertSame(
                $expected,
                $service->effectiveArtCostFor($character, $skill),
                "{$row['job_id']}:{$row['learn_rank']}:{$row['name']}",
            );
        }
    }

    public function test_all_282_v2_arts_have_a_fixed_sp_cost_from_their_source_job_tier(): void
    {
        $rows = json_decode(
            (string) file_get_contents(base_path('database/data/job_arts.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $expectedByTier = [
            'basic' => [1 => 4, 5 => 6, 9 => 8],
            'intermediate' => [1 => 6, 5 => 9, 9 => 13],
            'advanced' => [1 => 10, 5 => 16, 9 => 22],
            'super' => [1 => 16, 5 => 25, 9 => 35],
            'crown' => [1 => 23, 5 => 36, 9 => 50],
            'hero' => [1 => 30, 5 => 48, 9 => 66],
            'legend' => [1 => 40, 5 => 64, 9 => 88],
            'myth' => [1 => 52, 5 => 84, 9 => 115],
        ];
        $catalog = app(JobArtV2PrototypeCatalog::class);
        $calculator = app(JobArtV2SpCostCalculator::class);

        foreach ($rows as $row) {
            $jobId = (int) $row['job_id'];
            $rank = (int) $row['learn_rank'];
            $tier = $catalog->currentJobTier($jobId);
            $label = "{$jobId}:{$rank}:{$row['name']}";

            $this->assertNotNull($tier, $label);
            $this->assertSame(
                $expectedByTier[$tier][$rank],
                $calculator->forCurrentJob(new Skill($row), 999_999, 1, 'inherited'),
                $label,
            );
        }
    }

    public function test_flags_remain_fail_closed_for_every_current_job(): void
    {
        config([
            'battle.job_art_v2.loadout_v2' => false,
            'battle.job_art_v2.dynamic_single' => false,
            'battle.job_art_v2.normalized_sp' => false,
        ]);

        $gate = app(JobArtV2FeatureGate::class);
        foreach ($this->allJobs() as $jobId) {
            $actor = new BattleActor("job-{$jobId}", true, ['current_job_id' => $jobId]);

            $this->assertFalse($gate->usesLoadoutUiForCurrentJob($jobId), (string) $jobId);
            $this->assertFalse($gate->usesDynamicSingle($actor), (string) $jobId);
            $this->assertFalse($gate->usesPr5Rules($actor), (string) $jobId);
        }
    }

    public function test_every_current_job_has_an_explicit_v2_effect_coverage(): void
    {
        $catalog = app(JobArtV2PrototypeCatalog::class);

        foreach ($this->allJobs() as $jobId) {
            $this->assertContains($catalog->effectCoverageForCurrentJob($jobId), [
                'full_v2_effect',
                'resource_v2_master_effect',
            ], (string) $jobId);
        }

        $this->assertSame('full_v2_effect', $catalog->effectCoverageForCurrentJob(85));
    }

    public function test_enabled_flags_never_return_a_valid_master_job_to_legacy_rules(): void
    {
        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
        ]);

        $gate = app(JobArtV2FeatureGate::class);
        foreach ($this->allJobs() as $jobId) {
            $actor = new BattleActor("job-{$jobId}", true, ['current_job_id' => $jobId]);

            $this->assertTrue($gate->usesLoadoutUiForCurrentJob($jobId), (string) $jobId);
            $this->assertTrue($gate->usesDynamicSingle($actor), (string) $jobId);
            $this->assertTrue($gate->usesPr5Rules($actor), (string) $jobId);
            $this->assertTrue($gate->usesResources($actor), (string) $jobId);
        }
    }

    /** @return list<int> */
    private function allJobs(): array
    {
        return [...range(1, 38), ...range(44, 99)];
    }
}
