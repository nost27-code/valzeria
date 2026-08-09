<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\JobArtLineageCatalog;
use App\Services\JobArtV2LoadoutPresenter;
use Tests\TestCase;

class JobArtV2LoadoutPresenterTest extends TestCase
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
            'battle.job_art_v2.fields' => true,
            'battle.job_art_v2.penetration' => true,
            'battle.job_art_v2.penetration_stance' => true,
        ]);
    }

    public function test_priest_chain_uses_trusted_role_resource_field_and_finisher_metadata(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);

        $rankOne = $presenter->forArt(24, $this->skill(24, 1));
        $rankFive = $presenter->forArt(24, $this->skill(24, 5));
        $rankNine = $presenter->forArt(24, $this->skill(24, 9));

        $this->assertSame('始動', $rankOne['role_label']);
        $this->assertSame('星印 +4', $rankOne['resource_text']);
        $this->assertSame(['聖域の場を展開'], $rankOne['field_texts']);
        $this->assertSame('展開', $rankFive['role_label']);
        $this->assertSame('星印 4消費', $rankFive['resource_text']);
        $this->assertSame('奥義', $rankNine['role_label']);
        $this->assertSame('星印 12消費', $rankNine['resource_text']);
        $this->assertSame('条件成立時は最優先候補', $rankNine['priority_text']);
        $this->assertTrue($rankNine['is_ultimate']);
    }

    public function test_field_jobs_describe_only_catalog_backed_field_operations(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);

        $this->assertSame(
            ['星光の場を展開'],
            $presenter->forArt(53, $this->skill(53, 1))['field_texts']
        );
        $this->assertSame(
            ['現在の場を1ラウンド延長'],
            $presenter->forArt(53, $this->skill(53, 5))['field_texts']
        );
        $this->assertSame(
            ['星光の場を展開'],
            $presenter->forArt(85, $this->skill(85, 1))['field_texts']
        );
        $this->assertSame(
            ['現在の場を2ラウンド固定'],
            $presenter->forArt(85, $this->skill(85, 5))['field_texts']
        );
        $this->assertSame(
            ['旋律の副場を1ラウンド生成'],
            $presenter->forArt(85, $this->skill(85, 9))['field_texts']
        );
    }

    public function test_dragon_force_chain_describes_resource_stance_and_penetration_without_removed_bonus(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);

        $rankOne = $presenter->forArt(62, $this->skill(62, 1));
        $rankFive = $presenter->forArt(62, $this->skill(62, 5));
        $rankNine = $presenter->forArt(62, $this->skill(62, 9));

        $this->assertSame('竜気 +4', $rankOne['resource_text']);
        $this->assertSame(['貫通構えを取る'], $rankOne['stance_texts']);
        $this->assertSame('竜気 4消費', $rankFive['resource_text']);
        $this->assertSame(['構え時：物理DEF 35%貫通', '使用後、構えを再形成'], $rankFive['stance_texts']);
        $this->assertSame('竜気 12消費', $rankNine['resource_text']);
        $this->assertSame(['構え時：物理DEF 50%貫通'], $rankNine['stance_texts']);
        $this->assertStringNotContainsString('追加威力', json_encode([$rankOne, $rankFive, $rankNine], JSON_UNESCAPED_UNICODE));
    }

    public function test_eclipse_and_hunt_use_generic_role_resource_and_effective_power_metadata(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);

        foreach ([[61, '冥蝕', 585, 'magical'], [64, '狩猟印', 460, null]] as [$jobId, $resourceName, $rankNinePower, $damageCategory]) {
            $rankOne = $this->skill($jobId, 1, 225);
            $rankFive = $this->skill($jobId, 5, 285);
            $rankNine = $this->skill($jobId, 9, 355);
            foreach ([$rankOne, $rankFive, $rankNine] as $skill) {
                $skill->setAttribute('job_art_origin', 'current');
            }

            $this->assertSame('始動', $presenter->forArt($jobId, $rankOne)['role_label']);
            $this->assertSame("{$resourceName} +4", $presenter->forArt($jobId, $rankOne)['resource_text']);
            $this->assertSame("{$resourceName} 4消費", $presenter->forArt($jobId, $rankFive)['resource_text']);
            $this->assertSame("{$resourceName} 12消費", $presenter->forArt($jobId, $rankNine)['resource_text']);
            $this->assertSame($rankNinePower, $presenter->forArt($jobId, $rankNine)['effective_power']);
            $this->assertSame($damageCategory, $presenter->forArt($jobId, $rankNine)['damage_category']);
            $this->assertSame([], $presenter->forArt($jobId, $rankNine)['field_texts']);
            $this->assertSame([], $presenter->forArt($jobId, $rankNine)['stance_texts']);
        }
    }

    public function test_inherited_arts_show_source_lineage_without_foreign_v2_effects(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);

        $this->assertNull($presenter->forArt(90, $this->skill(90, 1)));

        $crossLineageInherited = $presenter->forArt(62, $this->skill(24, 1));
        $this->assertSame('継承・場術', $crossLineageInherited['source_badge']);
        $this->assertSame('field', $crossLineageInherited['source_lineage_key']);
        $this->assertNull($crossLineageInherited['resource_text']);
        $this->assertSame([], $crossLineageInherited['field_texts']);
        $this->assertSame([], $crossLineageInherited['effect_texts']);
        $this->assertNull($crossLineageInherited['priority_text']);

        $sharedLineageInherited = $presenter->forArt(53, $this->skill(24, 1));
        $this->assertSame('始動', $sharedLineageInherited['role_label']);
        $this->assertSame('継承・同系譜', $sharedLineageInherited['source_badge']);
        $this->assertSame('星印 +4', $sharedLineageInherited['resource_text']);
        $this->assertSame([], $sharedLineageInherited['field_texts']);

        $currentJobOnlyOverlay = $presenter->forArt(24, $this->skill(85, 9));
        $this->assertSame('奥義', $currentJobOnlyOverlay['role_label']);
        $this->assertSame([], $currentJobOnlyOverlay['field_texts']);
        $this->assertSame('星印 12消費', $currentJobOnlyOverlay['resource_text']);
        $this->assertSame('現在職奥義が使用不能なら優先候補', $currentJobOnlyOverlay['priority_text']);

        $unknownSource = $presenter->forArt(62, $this->skill(39, 5));
        $this->assertSame('継承', $unknownSource['source_badge']);
        $this->assertNull($unknownSource['source_lineage_key']);
        $this->assertNull($unknownSource['resource_text']);
    }

    public function test_job_63_portable_field_effects_are_visible_only_to_same_lineage_inheritance(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);

        $sameLineageRankOne = $this->skill(63, 1);
        $sameLineageRankOne->setAttribute('job_art_origin', 'inherited');
        $sameLineageRankFive = $this->skill(63, 5);
        $sameLineageRankFive->setAttribute('job_art_origin', 'inherited');

        $rankOne = $presenter->forArt(53, $sameLineageRankOne);
        $rankFive = $presenter->forArt(53, $sameLineageRankFive);

        $this->assertSame('継承・同系譜', $rankOne['source_badge']);
        $this->assertSame('星印 +4', $rankOne['resource_text']);
        $this->assertSame(
            ['5種類の場を固定順で次へ張り替え', '実際の場上書き時：星印+2（基礎+4と合計）'],
            $rankOne['field_texts']
        );
        $this->assertSame(
            ['直前に上書きされた自分の場を1ラウンド残響として保持'],
            $rankFive['field_texts']
        );

        $crossLineageRankOne = $this->skill(63, 1);
        $crossLineageRankOne->setAttribute('job_art_origin', 'inherited');
        $this->assertNull($presenter->forArt(62, $crossLineageRankOne)['resource_text']);
        $this->assertSame([], $presenter->forArt(62, $crossLineageRankOne)['field_texts']);
    }

    public function test_lineage_catalog_maps_only_formally_confirmed_jobs_and_preserves_production_keys(): void
    {
        $catalog = app(JobArtLineageCatalog::class);

        $this->assertCount(94, $catalog->mappedJobs());
        $this->assertSame(['lineage_key' => 'field', 'lineage_name' => '場術', 'source' => 'valzeria_job_art_field_v1_3_1.md'], $catalog->forJob(24));
        $this->assertSame('counter', $catalog->forJob(60)['lineage_key']);
        $this->assertSame('eclipse', $catalog->forJob(61)['lineage_key']);
        $this->assertSame('pierce', $catalog->forJob(62)['lineage_key']);
        $this->assertSame('aim', $catalog->forJob(94)['lineage_key']);
        $this->assertSame('hunt', $catalog->forJob(3)['lineage_key']);
        $this->assertNull($catalog->forJob(39));
    }

    public function test_flags_fail_closed_without_removing_trusted_role_labels(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);

        config(['battle.job_art_v2.resources' => false]);
        $withoutResources = $presenter->forArt(24, $this->skill(24, 9));
        $this->assertSame('奥義', $withoutResources['role_label']);
        $this->assertNull($withoutResources['resource_text']);
        $this->assertNull($withoutResources['priority_text']);
        $this->assertSame([], $withoutResources['field_texts']);

        config(['battle.job_art_v2.resources' => true, 'battle.job_art_v2.penetration_stance' => false]);
        $this->assertSame([], $presenter->forArt(62, $this->skill(62, 5))['stance_texts']);

        config(['battle.job_art_v2.loadout_v2' => false]);
        $this->assertFalse($presenter->enabledForCurrentJob(24));
        $this->assertNull($presenter->forArt(24, $this->skill(24, 1)));
    }

    public function test_effective_power_uses_current_job_overrides_without_changing_inherited_arts(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);

        $sageRankNine = $this->skill(53, 9, 320);
        $sageRankNine->setAttribute('job_art_origin', 'current');
        $this->assertSame(410, $presenter->forArt(53, $sageRankNine)['effective_power']);

        $lancerRankNine = $this->skill(62, 9, 355);
        $lancerRankNine->setAttribute('job_art_origin', 'current');
        $this->assertSame(470, $presenter->forArt(62, $lancerRankNine)['effective_power']);

        $inheritedSageRankNine = $this->skill(53, 9, 320);
        $inheritedSageRankNine->setAttribute('job_art_origin', 'inherited');
        $this->assertSame(320, $presenter->forArt(53, $inheritedSageRankNine)['effective_power']);
    }

    private function skill(int $jobId, int $rank, int $power = 0): Skill
    {
        $skill = new Skill([
            'job_id' => $jobId,
            'name' => "表示試験 {$jobId}-{$rank}",
            'skill_type' => 'job_art',
            'learn_rank' => $rank,
            'art_cost' => 1,
            'power' => $power,
        ]);
        $skill->setAttribute('id', ($jobId * 100) + $rank);

        return $skill;
    }
}
