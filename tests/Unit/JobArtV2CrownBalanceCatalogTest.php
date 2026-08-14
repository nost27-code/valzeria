<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\JobArtV2CardDescriptionCatalog;
use App\Services\JobArtV2CrownBalanceCatalog;
use ReflectionClass;
use Tests\TestCase;

final class JobArtV2CrownBalanceCatalogTest extends TestCase
{
    public function test_numeric_runtime_overrides_match_the_canonical_l_column_copy(): void
    {
        $runtime = (new ReflectionClass(JobArtV2CrownBalanceCatalog::class))->getConstant('ARTS');
        $descriptions = app(JobArtV2CardDescriptionCatalog::class)->all();
        $labels = [
            'str' => '攻撃',
            'def' => '防御',
            'mag' => '魔力',
            'spr' => '精神',
            'agi' => '敏捷',
        ];

        $this->assertIsArray($runtime);
        $this->assertCount(102, $runtime);

        foreach ($runtime as $key => $metadata) {
            $description = $descriptions[$key] ?? null;
            $this->assertIsString($description, "Canonical description is missing: {$key}");
            $normalEffect = explode("\n\n", $description, 2)[0];

            if (isset($metadata['duration'])) {
                $this->assertStringContainsString((int) $metadata['duration'].'ターン', $normalEffect, $key);
            }
            if (isset($metadata['hit_count'])) {
                $this->assertStringContainsString((int) $metadata['hit_count'].'回', $normalEffect, $key);
            }
            if (isset($metadata['heal_spr'])) {
                $this->assertStringContainsString('精神の'.(int) $metadata['heal_spr'].'%', $normalEffect, $key);
            }
            if (isset($metadata['heal_hp'])) {
                $this->assertStringContainsString('最大HPの'.(int) $metadata['heal_hp'].'%', $normalEffect, $key);
            }
            if (isset($metadata['reduction'])) {
                $this->assertStringContainsString((int) $metadata['reduction'].'%軽減', $normalEffect, $key);
            }
            if (isset($metadata['dynamic_buff']['rate'])) {
                $this->assertStringContainsString('+'.(int) $metadata['dynamic_buff']['rate'].'%', $normalEffect, $key);
            }

            foreach (($metadata['buffs'] ?? []) as $stat => $percent) {
                $this->assertModifierToken($normalEffect, $labels[$stat], '+', (int) $percent, $key);
            }
            foreach (($metadata['debuffs'] ?? []) as $stat => $percent) {
                $this->assertModifierToken($normalEffect, $labels[$stat], '-', (int) $percent, $key);
            }
        }
    }

    public function test_slash_uses_the_l_column_debuff_without_changing_its_master_power(): void
    {
        $source = new Skill([
            'job_id' => 1,
            'learn_rank' => 1,
            'name' => '斬り払い',
            'skill_type' => 'job_art',
            'power' => 90,
            'power_multiplier' => 0.9,
            'enemy_atk_down_percent' => 6,
            'duration_turns' => 3,
        ]);

        $execution = app(JobArtV2CrownBalanceCatalog::class)->applyToExecution($source);

        $this->assertNotSame($source, $execution);
        $this->assertSame(90, (int) $execution->power);
        $this->assertSame(12, (int) $execution->enemy_atk_down_percent);
        $this->assertSame(3, (int) $execution->duration_turns);
        $this->assertSame(6, (int) $source->enemy_atk_down_percent, 'The DB/master model must not be mutated.');
    }

    private function assertModifierToken(
        string $description,
        string $expectedStat,
        string $expectedSign,
        int $expectedPercent,
        string $key,
    ): void {
        preg_match_all(
            '/((?:攻撃|防御|魔力|精神|敏捷)(?:(?:・|と|／)(?:攻撃|防御|魔力|精神|敏捷))*)を([+-])(\d+)%/u',
            $description,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $stats = preg_split('/(?:・|と|／)/u', $match[1]) ?: [];
            if (in_array($expectedStat, $stats, true)
                && $match[2] === $expectedSign
                && (int) $match[3] === $expectedPercent) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail("{$key}: {$expectedStat}{$expectedSign}{$expectedPercent}% is missing from canonical copy: {$description}");
    }
}
