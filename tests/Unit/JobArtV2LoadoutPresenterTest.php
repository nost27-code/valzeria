<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\JobArtLineageCatalog;
use App\Services\JobArtV2CardDescriptionCatalog;
use App\Services\JobArtV2LoadoutPresenter;
use Tests\TestCase;

final class JobArtV2LoadoutPresenterTest extends TestCase
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
            'battle.job_art_v2.c_design_prototype' => false,
            'battle.job_art_v2.ultimate_counterplay' => true,
        ]);
    }

    public function test_all_192_l_column_descriptions_are_presented_identically_for_every_origin(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $catalog = app(JobArtV2CardDescriptionCatalog::class);
        $checked = 0;

        foreach ($this->masterRows() as $row) {
            $jobId = (int) $row['job_id'];
            if (! self::inCrownScope($jobId)) {
                continue;
            }

            $skill = $this->art($row, 'current');
            $expected = $catalog->defaultDescription($skill);
            $this->assertNotNull($expected, $this->key($skill));
            $current = $presenter->forArt($jobId, $skill);
            $this->assertSame($expected, $current['card_description'], $this->key($skill).' current');
            $this->assertSame($expected, $current['display_description'], $this->key($skill).' current display');

            $skill->setAttribute('job_art_origin', 'inherited');
            $otherCurrentJobId = $jobId === 62 ? 24 : 62;
            $other = $presenter->forArt($otherCurrentJobId, $skill);
            $this->assertSame($expected, $other['card_description'], $this->key($skill).' other lineage');
            $this->assertSame($expected, $other['display_description'], $this->key($skill).' other lineage display');
            $this->assertStringNotContainsString('継承', (string) $other['source_badge']);
            $this->assertStringNotContainsString('現在職', (string) $other['source_badge']);
            $checked++;
        }

        $this->assertSame(192, $checked);
    }

    public function test_other_lineage_cards_keep_their_own_resource_and_full_field_effects(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $fieldStarter = $this->masterArt(63, 1, '星冠詠唱', 'inherited');
        $fieldChain = $this->masterArt(63, 5, '星冠天導', 'inherited');

        $starter = $presenter->forArt(62, $fieldStarter);
        $chain = $presenter->forArt(62, $fieldChain);

        $this->assertSame('場術', $starter['source_badge']);
        $this->assertSame('images/icon/icon_290.webp', $starter['source_lineage_icon_path']);
        $this->assertSame('星印 +4（使用時）', $starter['resource_text']);
        $this->assertSame(
            ['5種類の場を固定順で次へ張り替え', '実際の場上書き時：星印+2（基礎+4と合計）'],
            $starter['field_texts'],
        );
        $this->assertSame('星印 -4（消費）', $chain['resource_text']);
        $this->assertSame(['直前に上書きされた自分の場を1ターン残響として保持'], $chain['field_texts']);
    }

    public function test_representative_role_resource_stance_and_priority_metadata_stays_structured(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $starter = $presenter->forArt(62, $this->masterArt(62, 1, '竜冠の槍印'));
        $chain = $presenter->forArt(62, $this->masterArt(62, 5, '竜冠穿槍'));
        $ultimate = $presenter->forArt(62, $this->masterArt(62, 9, '竜冠天穿槍'));

        $this->assertSame('始動', $starter['role_label']);
        $this->assertSame('竜気 +4（使用時）', $starter['resource_text']);
        $this->assertSame(['貫通構えを取る'], $starter['stance_texts']);
        $this->assertSame('連携', $chain['role_label']);
        $this->assertSame('竜気 -4（消費）', $chain['resource_text']);
        $this->assertSame('奥義', $ultimate['role_label']);
        $this->assertSame('竜気 -12（消費）', $ultimate['resource_text']);
        $this->assertTrue($ultimate['is_ultimate']);
    }

    public function test_l_column_power_is_the_same_for_current_and_other_lineage_cards(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $catalog = app(JobArtV2CardDescriptionCatalog::class);
        foreach ([[53, 9, '星天グランドスペル'], [60, 9, '王冠聖剣陣'], [64, 9, '影冠終葬射']] as [$jobId, $rank, $name]) {
            $skill = $this->masterArt($jobId, $rank, $name, 'current');
            $expected = $catalog->basePower($skill);
            $this->assertNotNull($expected);
            $this->assertSame($expected, $presenter->forArt($jobId, $skill)['effective_power']);
            $skill->setAttribute('job_art_origin', 'inherited');
            $this->assertSame($expected, $presenter->forArt(62, $skill)['effective_power']);
        }
    }

    public function test_unknown_jobs_fail_closed_but_every_master_job_is_supported(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $this->assertNull($presenter->forArt(39, $this->bareArt(39, 1)));

        foreach ([...range(1, 38), ...range(44, 99)] as $jobId) {
            $this->assertTrue($presenter->enabledForCurrentJob($jobId), "job {$jobId}");
        }
    }

    public function test_flags_fail_closed_without_removing_role_labels(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $art = $this->masterArt(24, 9, '大聖堂の奇跡');

        config(['battle.job_art_v2.resources' => false]);
        $display = $presenter->forArt(24, $art);
        $this->assertSame('奥義', $display['role_label']);
        $this->assertNull($display['resource_text']);

        config(['battle.job_art_v2.loadout_v2' => false]);
        $this->assertFalse($presenter->enabledForCurrentJob(24));
        $this->assertNull($presenter->forArt(24, $art));
    }

    public function test_lineage_catalog_preserves_all_94_master_job_tags(): void
    {
        $catalog = app(JobArtLineageCatalog::class);
        $this->assertCount(94, $catalog->mappedJobs());
        $this->assertSame('counter', $catalog->forJob(60)['lineage_key']);
        $this->assertSame('field', $catalog->forJob(63)['lineage_key']);
        $this->assertSame('aim', $catalog->forJob(94)['lineage_key']);
        $this->assertNull($catalog->forJob(39));
    }

    /** @return array<int, array<string, mixed>> */
    private function masterRows(): array
    {
        return json_decode(
            file_get_contents(database_path('data/job_arts.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private static function inCrownScope(int $jobId): bool
    {
        return ($jobId >= 1 && $jobId <= 38) || ($jobId >= 44 && $jobId <= 69);
    }

    private function masterArt(int $jobId, int $rank, string $name, string $origin = 'current'): Skill
    {
        $row = collect($this->masterRows())->first(
            static fn (array $candidate): bool => (int) $candidate['job_id'] === $jobId
                && (int) $candidate['learn_rank'] === $rank
                && (string) $candidate['name'] === $name,
        );
        $this->assertIsArray($row, "Missing Job Art {$jobId}:{$rank}:{$name}");

        return $this->art($row, $origin);
    }

    /** @param array<string, mixed> $row */
    private function art(array $row, string $origin): Skill
    {
        $skill = new Skill($row);
        $skill->setAttribute('id', ((int) $row['job_id'] * 100) + (int) $row['learn_rank']);
        $skill->setAttribute('job_art_origin', $origin);
        $skill->setAttribute('job_art_rate', 1.0);

        return $skill;
    }

    private function bareArt(int $jobId, int $rank): Skill
    {
        $skill = new Skill([
            'job_id' => $jobId,
            'name' => "unknown {$jobId}-{$rank}",
            'skill_type' => 'job_art',
            'learn_rank' => $rank,
            'effect_template' => 'PHYSICAL_DAMAGE',
        ]);
        $skill->setAttribute('id', ($jobId * 100) + $rank);

        return $skill;
    }

    private function key(Skill $skill): string
    {
        return (int) $skill->job_id.':'.(int) $skill->learn_rank.':'.(string) $skill->name;
    }
}
