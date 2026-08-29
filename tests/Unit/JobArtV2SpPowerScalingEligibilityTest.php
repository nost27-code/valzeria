<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\JobArtV2SpPowerScalingService;
use App\Support\JobArtMasterPowerParser;
use Tests\TestCase;

/** Freezes the SP-output eligibility boundary across all 282 master arts. */
class JobArtV2SpPowerScalingEligibilityTest extends TestCase
{
    /** @var array<string, string> */
    private const EXPECTED_EXCLUSIONS = [
        '7:1' => 'not_direct_damage',
        '7:5' => 'not_direct_damage',
        '7:9' => 'not_direct_damage',
        '8:1' => 'hp_to_sp_conversion',
        '8:9' => 'not_direct_damage',
        '10:9' => 'not_direct_damage',
        '12:1' => 'not_direct_damage',
        '12:5' => 'not_direct_damage',
        '13:1' => 'not_direct_damage',
        '14:1' => 'not_direct_damage',
        '15:1' => 'not_direct_damage',
        '15:9' => 'not_direct_damage',
        '20:1' => 'not_direct_damage',
        '20:9' => 'not_direct_damage',
        '21:1' => 'not_direct_damage',
        '21:9' => 'not_direct_damage',
        '23:5' => 'not_direct_damage',
        '23:9' => 'not_direct_damage',
        '24:1' => 'not_direct_damage',
        '24:9' => 'not_direct_damage',
        '25:1' => 'not_direct_damage',
        '25:5' => 'not_direct_damage',
        '25:9' => 'not_direct_damage',
        '26:1' => 'hp_to_sp_conversion',
        '27:1' => 'not_direct_damage',
        '28:1' => 'not_direct_damage',
        '30:1' => 'not_direct_damage',
        '31:1' => 'not_direct_damage',
        '31:9' => 'not_direct_damage',
        '32:1' => 'not_direct_damage',
        '33:1' => 'not_direct_damage',
        '34:1' => 'not_direct_damage',
        '35:1' => 'not_direct_damage',
        '36:1' => 'not_direct_damage',
        '37:1' => 'not_direct_damage',
        '38:1' => 'not_direct_damage',
        '38:5' => 'not_direct_damage',
        '38:9' => 'not_direct_damage',
        '47:1' => 'not_direct_damage',
        '47:5' => 'not_direct_damage',
        '47:9' => 'not_direct_damage',
        '49:1' => 'hp_to_sp_conversion',
        '57:1' => 'hp_to_sp_conversion',
        '77:1' => 'hp_to_sp_conversion',
        '91:1' => 'hp_to_sp_conversion',
        '97:1' => 'recovers_sp',
        '97:5' => 'recovers_sp',
    ];

    /** @var list<Skill> */
    private array $arts = [];

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
            'battle.job_art_v2.c_design_prototype' => true,
            'battle.job_art_v2.rank5_v6' => true,
            'battle.job_art_v2.sp_power_scaling.enabled' => true,
        ]);

        $this->arts = $this->allArts();
    }

    public function test_all_master_arts_have_a_frozen_eligibility_result(): void
    {
        $service = app(JobArtV2SpPowerScalingService::class);
        $reasons = [];
        $eligible = 0;
        $excluded = [];

        $this->assertCount(282, $this->arts);
        foreach ($this->arts as $art) {
            $reason = $service->exclusionReason($art);
            if ($reason === null) {
                $eligible++;

                continue;
            }

            $excluded[sprintf('%d:%d', $art->job_id, $art->learn_rank)] = $reason;
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        }

        ksort($excluded, SORT_NATURAL);

        $this->assertSame(self::EXPECTED_EXCLUSIONS, $excluded);
        $this->assertSame(235, $eligible, json_encode($reasons, JSON_UNESCAPED_UNICODE));
        $this->assertSame(47, array_sum($reasons));
        $this->assertSame(6, $reasons['hp_to_sp_conversion'] ?? 0);
        $this->assertSame(2, $reasons['recovers_sp'] ?? 0);
        $this->assertSame(39, $reasons['not_direct_damage'] ?? 0);
    }

    public function test_eligibility_is_intrinsic_to_the_card_not_the_using_job(): void
    {
        $service = app(JobArtV2SpPowerScalingService::class);

        foreach ($this->arts as $art) {
            $expected = $service->isEligibleArt($art);
            $clone = clone $art;
            $clone->setAttribute('job_art_origin', 'inherited');

            $this->assertSame(
                $expected,
                $service->isEligibleArt($clone),
                sprintf('%s:%s:%s changed by origin metadata', $art->job_id, $art->learn_rank, $art->name),
            );
        }
    }

    public function test_sp_generating_arts_keep_fixed_cost_only(): void
    {
        $service = app(JobArtV2SpPowerScalingService::class);

        foreach ([
            [8, 1], [26, 1], [49, 1], [57, 1], [77, 1], [91, 1],
            [97, 1], [97, 5],
        ] as [$jobId, $rank]) {
            $art = $this->art($jobId, $rank);
            $this->assertNotNull($art, "missing art {$jobId}:{$rank}");
            $this->assertContains(
                $service->exclusionReason($art),
                ['recovers_sp', 'recovers_sp_role_effect', 'hp_to_sp_conversion'],
            );

            $result = $service->forReference($art, 1, 10_000, 50, 'max');
            $this->assertSame(0, $result->variableCost);
            $this->assertSame(50, $result->totalCost);
            $this->assertFalse($result->powerScalingApplies);
        }
    }

    public function test_previously_origin_sensitive_cards_use_their_intrinsic_damage_metadata(): void
    {
        $service = app(JobArtV2SpPowerScalingService::class);

        foreach ([[22, 5], [23, 1], [70, 5]] as [$jobId, $rank]) {
            $art = $this->art($jobId, $rank);
            $this->assertNotNull($art, "missing art {$jobId}:{$rank}");
            $this->assertTrue(
                $service->isEligibleArt($art),
                "{$jobId}:{$rank} must use its card-intrinsic direct-damage semantics",
            );
        }
    }

    public function test_flag_off_keeps_all_arts_on_fixed_cost_and_base_power(): void
    {
        config(['battle.job_art_v2.sp_power_scaling.enabled' => false]);
        $service = app(JobArtV2SpPowerScalingService::class);

        foreach ($this->arts as $art) {
            $result = $service->forReference($art, 1, 10_000, 50, 'max');
            $this->assertSame(0, $result->variableCost);
            $this->assertSame(50, $result->totalCost);
            $this->assertSame(0, $result->bonusBps);
            $this->assertFalse($result->powerScalingApplies);
        }
    }

    private function art(int $jobId, int $rank): ?Skill
    {
        foreach ($this->arts as $art) {
            if ((int) $art->job_id === $jobId && (int) $art->learn_rank === $rank) {
                return $art;
            }
        }

        return null;
    }

    /** @return list<Skill> */
    private function allArts(): array
    {
        $rows = json_decode(
            (string) file_get_contents(base_path('database/data/job_arts.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return array_map(static function (array $row): Skill {
            $powerHint = $row['power_hint'] ?? 0;
            $power = JobArtMasterPowerParser::parse($powerHint);
            $skill = new Skill(array_merge($row, [
                'power' => $power,
                'power_multiplier' => $power / 100,
            ]));
            $skill->skill_type = 'job_art';
            $skill->job_id = (int) $row['job_id'];
            $skill->learn_rank = (int) $row['learn_rank'];
            $skill->name = (string) $row['name'];
            $skill->effect_template = (string) ($row['effect_template'] ?? '');
            $skill->limit_group = strtoupper((string) ($row['limit_group'] ?? 'NONE'));

            return $skill;
        }, $rows);
    }
}
