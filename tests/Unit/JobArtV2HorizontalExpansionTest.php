<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\JobArtV2PrototypeCatalog;
use Tests\TestCase;

class JobArtV2HorizontalExpansionTest extends TestCase
{
    public function test_approved_first_and_second_wave_jobs_join_the_existing_vertical_slice(): void
    {
        $catalog = app(JobArtV2PrototypeCatalog::class);

        foreach ([24, 53, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 85] as $jobId) {
            $this->assertTrue($catalog->supportsCurrentJob($jobId), (string) $jobId);
        }
    }

    public function test_first_wave_metadata_is_limited_to_frozen_resource_roles(): void
    {
        $catalog = app(JobArtV2PrototypeCatalog::class);

        foreach ([61 => ['eclipse', '冥蝕'], 64 => ['hunt', '狩猟印']] as $jobId => [$resourceKey, $resourceName]) {
            $job = $catalog->jobResourceMetadata($jobId);
            $this->assertSame($resourceKey, $job['resource_key']);
            $this->assertSame($resourceName, $job['resource_name']);
            $this->assertSame(12, $job['resource_max_points']);

            foreach ([1 => ['producer', 4, 0], 5 => ['consumer', 0, 4], 9 => ['finisher', 0, 12]] as $rank => [$role, $gain, $cost]) {
                $art = $catalog->artResourceMetadataForJobRank($jobId, $rank);
                $this->assertSame($role, $art['resource_role']);
                $this->assertSame($gain, $art['resource_gain_points']);
                $this->assertSame($cost, $art['resource_cost_points']);
            }
        }

        $this->assertSame('job_art_hit', $catalog->artResourceMetadataForJobRank(61, 1)['resource_gain_event']);
        $this->assertSame(2, $catalog->jobResourceMetadata(61)['self_damage_gain_points']);
        $this->assertArrayNotHasKey('resource_gain_event', $catalog->artResourceMetadataForJobRank(64, 1));
        $this->assertArrayNotHasKey('self_damage_gain_points', $catalog->jobResourceMetadata(64));
    }

    public function test_second_wave_metadata_matches_the_frozen_aim_and_command_rules(): void
    {
        $catalog = app(JobArtV2PrototypeCatalog::class);

        $aim = $catalog->jobResourceMetadata(65);
        $this->assertSame(['aim', '照準', 12], [$aim['resource_key'], $aim['resource_name'], $aim['resource_max_points']]);
        $this->assertSame(1, $aim['normal_attack_hit_gain_points']);
        $this->assertSame(2, $aim['normal_attack_miss_gain_points']);
        $this->assertSame(10, $catalog->artResourceMetadataForJobRank(65, 5)['accuracy_delta_points']);
        $this->assertSame(0.03, $catalog->artResourceMetadataForJobRank(65, 5)['sp_pressure_rate']);
        $this->assertSame(15, $catalog->artResourceMetadataForJobRank(65, 9)['accuracy_delta_points']);
        $this->assertSame(0.05, $catalog->artResourceMetadataForJobRank(65, 9)['sp_pressure_rate']);

        $command = $catalog->jobResourceMetadata(69);
        $this->assertSame(['command_points', '指揮点', 12], [$command['resource_key'], $command['resource_name'], $command['resource_max_points']]);
        $this->assertSame(4, $command['normal_attack_hit_gain_points']);
        $this->assertSame(1, $command['non_job_art_action_gain_points']);
        $this->assertSame(4, $catalog->artResourceMetadataForJobRank(69, 1)['resource_gain_points']);
    }

    public function test_third_wave_metadata_matches_the_frozen_transmute_and_break_rules(): void
    {
        $catalog = app(JobArtV2PrototypeCatalog::class);

        $transmute = $catalog->jobResourceMetadata(67);
        $this->assertSame(['catalyst', '触媒', 12, 1], [
            $transmute['resource_key'],
            $transmute['resource_name'],
            $transmute['resource_max_points'],
            $transmute['normal_attack_hit_gain_points'],
        ]);
        $this->assertSame(['producer', 0, 0], [
            $catalog->artResourceMetadataForJobRank(67, 1)['resource_role'],
            $catalog->artResourceMetadataForJobRank(67, 1)['resource_cost_points'],
            $catalog->artResourceMetadataForJobRank(67, 1)['minimum_resource_points'],
        ]);
        $this->assertSame('job_art_cast', $catalog->artResourceMetadataForJobRank(67, 1)['resource_gain_event']);
        $this->assertSame([8, 8], [
            $catalog->artResourceMetadataForJobRank(67, 9)['resource_cost_points'],
            $catalog->artResourceMetadataForJobRank(67, 9)['minimum_resource_points'],
        ]);

        $break = $catalog->jobResourceMetadata(68);
        $this->assertSame(['break', '崩し', 12, 1], [
            $break['resource_key'],
            $break['resource_name'],
            $break['resource_max_points'],
            $break['normal_attack_hit_gain_points'],
        ]);
        $this->assertSame('job_art_hit', $catalog->artResourceMetadataForJobRank(68, 1)['resource_gain_event']);
        $this->assertArrayNotHasKey('break_rate', $catalog->artResourceMetadataForJobRank(68, 5));
        $this->assertArrayNotHasKey('break_rounds', $catalog->artResourceMetadataForJobRank(68, 9));
    }

    public function test_fourth_wave_metadata_matches_the_frozen_counter_and_guard_rules(): void
    {
        $catalog = app(JobArtV2PrototypeCatalog::class);

        $counter = $catalog->jobResourceMetadata(60);
        $this->assertSame(['counter', 'sword_momentum', '剣勢', 12, 1, 1, 1], [
            $counter['lineage_key'],
            $counter['resource_key'],
            $counter['resource_name'],
            $counter['resource_max_points'],
            $counter['normal_attack_hit_gain_points'],
            $counter['physical_attack_received_gain_points'],
            $counter['parry_success_gain_points'],
        ]);
        $this->assertSame([4, 0.20], [
            $catalog->artResourceMetadataForJobRank(60, 1)['counter_stance_rounds'],
            $catalog->artResourceMetadataForJobRank(60, 1)['parry_rate'],
        ]);

        $guard = $catalog->jobResourceMetadata(66);
        $this->assertSame(['guard', 'holy_guard', '聖護', 12, 1, 1, 1], [
            $guard['lineage_key'],
            $guard['resource_key'],
            $guard['resource_name'],
            $guard['resource_max_points'],
            $guard['normal_attack_hit_gain_points'],
            $guard['damage_mitigated_gain_points'],
            $guard['cleanse_success_gain_points'],
        ]);
        $this->assertSame(0.25, $catalog->artResourceMetadataForJobRank(66, 1)['guard_rate']);
        $this->assertTrue($catalog->artResourceMetadataForJobRank(66, 5)['cleanse_harmful_states']);
        $this->assertSame(0.45, $catalog->artResourceMetadataForJobRank(66, 9)['guard_rate']);
    }

    public function test_existing_master_effects_are_reused_without_inferred_lineage_effects(): void
    {
        $rows = json_decode((string) file_get_contents(base_path('database/data/job_arts.json')), true, 512, JSON_THROW_ON_ERROR);
        $byJobRank = [];
        foreach ($rows as $row) {
            $jobId = (int) ($row['job_id'] ?? 0);
            $rank = (int) ($row['learn_rank'] ?? 0);
            if (in_array($jobId, [61, 64], true) && in_array($rank, [1, 5, 9], true)) {
                $byJobRank[$jobId][$rank] = $row;
            }
        }

        foreach ([61, 64] as $jobId) {
            $this->assertSame([1, 5, 9], array_keys($byJobRank[$jobId]));
            foreach ([1 => 225, 5 => 285, 9 => 355] as $rank => $masterPower) {
                $this->assertSame('PHYSICAL_DAMAGE', $byJobRank[$jobId][$rank]['effect_template']);
                $this->assertSame($masterPower, (int) $byJobRank[$jobId][$rank]['power_hint']);
                $this->assertSame('NONE', $byJobRank[$jobId][$rank]['limit_group']);
            }
        }

        $this->assertSame(1, (int) $byJobRank[61][9]['max_uses_per_battle']);
        $this->assertSame(1, (int) $byJobRank[64][9]['max_uses_per_battle']);
    }

    public function test_unregistered_ranks_and_cross_job_arts_remain_untrusted(): void
    {
        $catalog = app(JobArtV2PrototypeCatalog::class);

        $this->assertTrue($catalog->isTrustedCurrentJobArt(61, $this->art(61, 1)));
        $this->assertTrue($catalog->isTrustedCurrentJobArt(64, $this->art(64, 9)));
        $this->assertTrue($catalog->isTrustedCurrentJobArt(65, $this->art(65, 1)));
        $this->assertTrue($catalog->isTrustedCurrentJobArt(69, $this->art(69, 9)));
        $this->assertTrue($catalog->isTrustedCurrentJobArt(60, $this->art(60, 1)));
        $this->assertTrue($catalog->isTrustedCurrentJobArt(66, $this->art(66, 9)));
        $this->assertFalse($catalog->isTrustedCurrentJobArt(61, $this->art(64, 1)));
        $this->assertFalse($catalog->isTrustedCurrentJobArt(64, $this->art(64, 3)));
        $this->assertFalse($catalog->isTrustedCurrentJobArt(65, $this->art(69, 1)));
    }

    private function art(int $jobId, int $rank): Skill
    {
        $skill = new Skill([
            'name' => "job-{$jobId}-rank-{$rank}",
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
        ]);
        $skill->setAttribute('id', ($jobId * 100) + $rank);

        return $skill;
    }
}
