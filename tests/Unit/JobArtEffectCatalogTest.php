<?php

namespace Tests\Unit;

use App\Support\JobArtEffectCatalog;
use App\Support\JobArtMasterValidator;
use PHPUnit\Framework\TestCase;

class JobArtEffectCatalogTest extends TestCase
{
    public function test_damage_reward_templates_declare_both_damage_and_rewards(): void
    {
        $this->assertTrue(JobArtEffectCatalog::dealsDamage('PHYSICAL_DAMAGE_REWARD'));
        $this->assertSame('physical', JobArtEffectCatalog::damageType('PHYSICAL_DAMAGE_REWARD'));
        $this->assertTrue(JobArtEffectCatalog::appliesGoldBonus('PHYSICAL_DAMAGE_REWARD'));
        $this->assertTrue(JobArtEffectCatalog::appliesDropBonus('PHYSICAL_DAMAGE_REWARD'));

        $this->assertTrue(JobArtEffectCatalog::dealsDamage('MAGICAL_DAMAGE_REWARD'));
        $this->assertSame('magical', JobArtEffectCatalog::damageType('MAGICAL_DAMAGE_REWARD'));
        $this->assertTrue(JobArtEffectCatalog::appliesGoldBonus('MAGICAL_DAMAGE_REWARD'));
        $this->assertTrue(JobArtEffectCatalog::appliesDropBonus('MAGICAL_DAMAGE_REWARD'));

        $this->assertTrue(JobArtEffectCatalog::dealsDamage('PHYSICAL_DAMAGE_GOLD_REWARD'));
        $this->assertSame('physical', JobArtEffectCatalog::damageType('PHYSICAL_DAMAGE_GOLD_REWARD'));
        $this->assertTrue(JobArtEffectCatalog::appliesGoldBonus('PHYSICAL_DAMAGE_GOLD_REWARD'));
        $this->assertFalse(JobArtEffectCatalog::appliesDropBonus('PHYSICAL_DAMAGE_GOLD_REWARD'));
    }

    public function test_support_and_reward_only_templates_do_not_deal_damage(): void
    {
        $this->assertFalse(JobArtEffectCatalog::dealsDamage('GUARD_BARRIER'));
        $this->assertSame(0, JobArtEffectCatalog::hitCount('GUARD_BARRIER'));

        $this->assertFalse(JobArtEffectCatalog::dealsDamage('REWARD_MIXED'));
        $this->assertTrue(JobArtEffectCatalog::appliesGoldBonus('REWARD_MIXED'));
        $this->assertTrue(JobArtEffectCatalog::appliesDropBonus('REWARD_MIXED'));
    }

    public function test_every_job_art_json_template_is_registered(): void
    {
        $rows = json_decode((string) file_get_contents(__DIR__ . '/../../database/data/job_arts.json'), true);
        $templates = array_unique(array_map(
            static fn (array $row): string => (string) ($row['effect_template'] ?? ''),
            $rows
        ));

        $unknown = array_values(array_filter(
            $templates,
            static fn (string $template): bool => ! JobArtEffectCatalog::has($template)
        ));

        $this->assertSame([], $unknown);
    }

    public function test_validator_rejects_damage_text_on_non_damage_template(): void
    {
        $problems = JobArtMasterValidator::validateRows([
            [
                'job_id' => 999,
                'name' => '検査用奥義',
                'effect_template' => 'REWARD_MIXED',
                'memo' => '敵単体に中ダメージ。Gold/Drop小補正',
            ],
        ]);

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('ダメージを与えない実装です', $problems[0]);
    }

    public function test_validator_rejects_unimplemented_memo_terms(): void
    {
        $problems = JobArtMasterValidator::validateRows([
            [
                'job_id' => 999,
                'name' => '検査用奥義',
                'effect_template' => 'SELF_BUFF',
                'memo' => '自身ATK上昇（3ターン）',
            ],
        ]);

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('ターン', implode("\n", $problems));
    }

    public function test_validator_rejects_debuff_text_without_structured_field(): void
    {
        $problems = JobArtMasterValidator::validateRows([
            [
                'job_id' => 999,
                'name' => '検査用奥義',
                'effect_template' => 'DAMAGE_DEBUFF',
                'memo' => '単体攻撃＋敵ATK低下（戦闘中）',
            ],
        ]);

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('enemy_atk_down_percent', implode("\n", $problems));
    }

    public function test_validator_rejects_multi_hit_text_without_multi_hit_count(): void
    {
        $problems = JobArtMasterValidator::validateRows([
            [
                'job_id' => 999,
                'name' => '検査用奥義',
                'effect_template' => 'GUARD_BARRIER',
                'memo' => '分身による複数Hit＋分身バリア。1戦1回',
            ],
        ]);

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('複数Hit', implode("\n", $problems));
    }

    public function test_validator_rejects_luck_scaling_text_without_luk_power_rate(): void
    {
        $problems = JobArtMasterValidator::validateRows([
            [
                'job_id' => 999,
                'learn_rank' => 5,
                'name' => '検査用奥義',
                'effect_template' => 'PHYSICAL_DAMAGE_GOLD_REWARD',
                'gold_bonus_percent' => 2,
                'drop_bonus_percent' => 0,
                'memo' => 'LUK依存多段攻撃＋通常探索勝利時Gold小補正',
                'hit_count' => 2,
            ],
        ]);

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('luk_power_rate', implode("\n", $problems));
    }

    public function test_current_job_art_json_has_no_memo_template_mismatch(): void
    {
        $rows = json_decode((string) file_get_contents(__DIR__ . '/../../database/data/job_arts.json'), true);

        $this->assertSame([], JobArtMasterValidator::validateRows($rows));
    }

    public function test_damage_reward_job_arts_use_explicit_low_reward_bonuses(): void
    {
        $rows = json_decode((string) file_get_contents(__DIR__ . '/../../database/data/job_arts.json'), true);
        $expectedByRank = [1 => 1, 5 => 2, 9 => 3];

        foreach ($rows as $row) {
            if (! in_array((string) ($row['effect_template'] ?? ''), ['PHYSICAL_DAMAGE_REWARD', 'MAGICAL_DAMAGE_REWARD'], true)) {
                continue;
            }

            $expected = $expectedByRank[(int) $row['learn_rank']];
            $this->assertSame($expected, (int) ($row['gold_bonus_percent'] ?? -1), (string) $row['name']);
            $this->assertSame($expected, (int) ($row['drop_bonus_percent'] ?? -1), (string) $row['name']);
        }
    }

    public function test_gold_only_damage_reward_job_arts_use_explicit_gold_bonus_only(): void
    {
        $rows = json_decode((string) file_get_contents(__DIR__ . '/../../database/data/job_arts.json'), true);
        $expectedByRank = [1 => 1, 5 => 2, 9 => 3];

        foreach ($rows as $row) {
            if ((string) ($row['effect_template'] ?? '') !== 'PHYSICAL_DAMAGE_GOLD_REWARD') {
                continue;
            }

            $expected = $expectedByRank[(int) $row['learn_rank']];
            $this->assertSame($expected, (int) ($row['gold_bonus_percent'] ?? -1), (string) $row['name']);
            $this->assertSame(0, (int) ($row['drop_bonus_percent'] ?? -1), (string) $row['name']);
        }
    }
}
