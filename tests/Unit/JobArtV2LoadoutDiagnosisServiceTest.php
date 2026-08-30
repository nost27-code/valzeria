<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\CharacterJobArtSlot;
use App\Models\Skill;
use App\Services\JobArtV2LoadoutDiagnosisService;
use App\Services\JobArtV2Rank5V6Catalog;
use Illuminate\Support\Collection;
use Tests\TestCase;

class JobArtV2LoadoutDiagnosisServiceTest extends TestCase
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
            'battle.job_art_v2.normalized_sp' => true,
            'battle.job_art_v2.rank5_v6' => true,
        ]);
    }

    public function test_spender_without_its_starter_warns_when_the_lineage_has_passive_gain(): void
    {
        $character = new Character(['current_job_id' => 62, 'mp_base' => 1000]);
        $slots = collect([
            $this->slot(1, $this->skill(62, 1, 'current'), 1),
            $this->slot(2, $this->skill(64, 5, 'inherited'), 2),
        ]);

        $result = app(JobArtV2LoadoutDiagnosisService::class)->diagnose(
            $character,
            $slots,
            'normal',
            1000,
            'normal',
            5,
            9,
        );

        $this->assertSame('warning', $result['status']);
        $this->assertContains('狩猟印は受動獲得頼みです', array_column($result['checks'], 'title'));
    }

    public function test_complete_main_chain_reports_resource_cycle_and_sp_policy(): void
    {
        $character = new Character(['current_job_id' => 62, 'mp_base' => 3000]);
        $slots = new Collection([
            $this->slot(1, $this->skill(62, 1, 'current'), 1),
            $this->slot(2, $this->skill(62, 5, 'current'), 2),
            $this->slot(3, $this->skill(62, 9, 'current'), 3),
        ]);

        $result = app(JobArtV2LoadoutDiagnosisService::class)->diagnose(
            $character,
            $slots,
            'boss',
            3000,
            'conserve',
            5,
            9,
        );

        $titles = array_column($result['checks'], 'title');
        $this->assertContains('竜気の循環成立', $titles);
        $this->assertContains('SP方針で発動可能', $titles);
        $this->assertSame('warning', $result['status']);
        $this->assertContains('空き枠があります', $titles);
    }

    public function test_rank_five_without_same_lineage_ultimate_reports_natural_cycle(): void
    {
        $character = new Character(['current_job_id' => 1, 'mp_base' => 1000]);
        $slots = collect([
            $this->slot(1, $this->skill(1, 1, 'current'), 1),
            $this->slot(2, $this->rankFive(1, 'current'), 2),
            $this->slot(3, $this->skill(3, 9, 'inherited'), 3),
        ]);

        $result = app(JobArtV2LoadoutDiagnosisService::class)->diagnose(
            $character,
            $slots,
            'normal',
            1000,
            'normal',
            5,
            9,
        );

        $titles = array_column($result['checks'], 'title');
        $this->assertContains('剣勢の自然循環成立', $titles);
        $naturalCycle = collect($result['checks'])->firstWhere('title', '剣勢の自然循環成立');
        $this->assertStringContainsString('12', (string) ($naturalCycle['detail'] ?? ''));
    }

    public function test_fourth_scheduled_rank_five_is_reported_as_unreachable_above_the_cap(): void
    {
        $character = new Character(['current_job_id' => 50, 'mp_base' => 5000]);
        $slots = collect([
            $this->slot(1, $this->skill(50, 1, 'current'), 1),
            $this->slot(2, $this->rankFive(1, 'inherited'), 2),
            $this->slot(3, $this->rankFive(11, 'inherited'), 2),
            $this->slot(4, $this->rankFive(13, 'inherited'), 2),
            $this->slot(5, $this->rankFive(50, 'current'), 2),
        ]);

        $result = app(JobArtV2LoadoutDiagnosisService::class)->diagnose(
            $character,
            $slots,
            'boss',
            5000,
            'aggressive',
            5,
            9,
        );

        $this->assertSame('invalid', $result['status']);
        $unreachable = collect($result['checks'])->firstWhere('title', '剣勢で発動できない連携があります');
        $this->assertIsArray($unreachable);
        $this->assertStringContainsString('必要16', (string) $unreachable['detail']);
        $this->assertStringContainsString('上限12', (string) $unreachable['detail']);
    }

    private function rankFive(int $jobId, string $origin): Skill
    {
        $name = app(JobArtV2Rank5V6Catalog::class)->all()[$jobId]['name'];

        return $this->skill($jobId, 5, $origin, $name);
    }

    private function skill(int $jobId, int $rank, string $origin, ?string $name = null): Skill
    {
        $skill = new Skill([
            'job_id' => $jobId,
            'name' => $name ?? "診断試験 {$jobId}-{$rank}",
            'skill_type' => 'job_art',
            'learn_rank' => $rank,
            'art_cost' => match ($rank) { 1 => 1, 5 => 2, 9 => 3 },
            'power' => 100,
            'effect_template' => 'PHYSICAL_DAMAGE',
        ]);
        $skill->setAttribute('id', ($jobId * 100) + $rank);
        $skill->setAttribute('job_art_origin', $origin);

        return $skill;
    }

    private function slot(int $slotNo, Skill $skill, int $cost): CharacterJobArtSlot
    {
        $slot = new CharacterJobArtSlot([
            'slot_no' => $slotNo,
            'skill_id' => (int) $skill->id,
        ]);
        $slot->setRelation('skill', $skill);
        $slot->setAttribute('job_art_active', true);
        $slot->setAttribute('job_art_effective_cost', $cost);

        return $slot;
    }
}
