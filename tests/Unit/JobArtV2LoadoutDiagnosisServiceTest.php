<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\CharacterJobArtSlot;
use App\Models\Skill;
use App\Services\JobArtV2LoadoutDiagnosisService;
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

    private function skill(int $jobId, int $rank, string $origin): Skill
    {
        $skill = new Skill([
            'job_id' => $jobId,
            'name' => "診断試験 {$jobId}-{$rank}",
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
