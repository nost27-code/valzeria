<?php

namespace App\Support;

class JobArtMasterValidator
{
    private const DAMAGE_KEYWORDS = ['ダメージ'];
    private const GOLD_KEYWORDS = ['Gold', 'GOLD', 'gold'];
    private const DROP_KEYWORDS = ['Drop', 'DROP', 'drop', '素材率', 'ドロップ', '報酬判定'];
    private const FORBIDDEN_MEMO_WORDS = ['ターン', '反撃', '継続ダメージ', '解除', '状態異常', '回避率', '会心率'];
    private const DAMAGE_REWARD_TEMPLATES = ['PHYSICAL_DAMAGE_REWARD', 'MAGICAL_DAMAGE_REWARD'];
    private const GOLD_ONLY_DAMAGE_REWARD_TEMPLATES = ['PHYSICAL_DAMAGE_GOLD_REWARD'];
    private const DAMAGE_REWARD_BONUS_BY_RANK = [1 => 1, 5 => 2, 9 => 3];

    public static function validateRows(array $rows): array
    {
        $problems = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $problems[] = 'job_arts.json: 配列ではない行があります。';
                continue;
            }

            $jobId = (int) ($row['job_id'] ?? 0);
            $name = (string) ($row['name'] ?? '');
            $template = (string) ($row['effect_template'] ?? '');
            $memo = (string) ($row['memo'] ?? '');

            if ($template === '' || ! JobArtEffectCatalog::has($template)) {
                $problems[] = sprintf(
                    'job_id=%d %s: 未定義の effect_template=%s が指定されています',
                    $jobId,
                    $name,
                    $template ?: '(空)'
                );
                continue;
            }

            if (self::memoMentionsDamage($memo) && ! JobArtEffectCatalog::dealsDamage($template)) {
                $problems[] = sprintf(
                    'job_id=%d %s: memoは「%s」だが effect_template=%s はダメージを与えない実装です',
                    $jobId,
                    $name,
                    $memo,
                    $template
                );
            }

            if (self::memoMentionsGoldBonus($memo) && ! JobArtEffectCatalog::appliesGoldBonus($template)) {
                $problems[] = sprintf(
                    'job_id=%d %s: memoはGold補正を示しますが effect_template=%s はGold補正を持ちません',
                    $jobId,
                    $name,
                    $template
                );
            }

            if (self::memoMentionsDropBonus($memo) && ! JobArtEffectCatalog::appliesDropBonus($template)) {
                $problems[] = sprintf(
                    'job_id=%d %s: memoは素材/Drop補正を示しますが effect_template=%s はDrop補正を持ちません',
                    $jobId,
                    $name,
                    $template
                );
            }

            foreach (self::FORBIDDEN_MEMO_WORDS as $word) {
                if (str_contains($memo, $word) && ! str_contains($memo, '命中率に影響')) {
                    $problems[] = sprintf(
                        'job_id=%d %s: memoに未実装または非推奨の表現「%s」が含まれています。戦闘中表記または実効果に合わせた表現へ修正してください',
                        $jobId,
                        $name,
                        $word
                    );
                }
            }

            foreach (self::requiredDebuffsForMemo($memo) as $field => $label) {
                if ((int) ($row[$field] ?? 0) <= 0) {
                    $problems[] = sprintf(
                        'job_id=%d %s: memoは%s低下を示しますが %s が設定されていません',
                        $jobId,
                        $name,
                        $label,
                        $field
                    );
                }
            }

            if (preg_match('/([2-9])回/u', $memo, $matches)) {
                $expectedHits = (int) $matches[1];
                $actualHits = (int) ($row['hit_count'] ?? JobArtEffectCatalog::hitCount($template));
                if ($actualHits !== $expectedHits) {
                    $problems[] = sprintf(
                        'job_id=%d %s: memoは%d回攻撃ですが hit_count=%d です',
                        $jobId,
                        $name,
                        $expectedHits,
                        $actualHits
                    );
                }
            }

            if (preg_match('/複数\s*HIT|複数\s*Hit|複数\s*hit/u', $memo)) {
                $actualHits = (int) ($row['hit_count'] ?? JobArtEffectCatalog::hitCount($template));
                if ($actualHits < 2) {
                    $problems[] = sprintf(
                        'job_id=%d %s: memoは複数Hitですが hit_count=%d です',
                        $jobId,
                        $name,
                        $actualHits
                    );
                }
            }

            if (self::memoMentionsLuckScaling($memo) && (float) ($row['luk_power_rate'] ?? 0) <= 0) {
                $problems[] = sprintf(
                    'job_id=%d %s: memoはLUK依存ですが luk_power_rate が設定されていません',
                    $jobId,
                    $name
                );
            }

            if (self::memoMentionsHpRecovery($memo)
                && ! in_array($template, ['HEAL', 'HEAL_CLEANSE'], true)
                && (int) ($row['heal_percent'] ?? 0) <= 0
                && (float) ($row['drain_hp_rate'] ?? 0) <= 0
            ) {
                $problems[] = sprintf('job_id=%d %s: memoはHP回復を示しますが回復効果が設定されていません', $jobId, $name);
            }

            if (preg_match('/被ダメ.{0,8}軽減/u', $memo)
                && ! in_array($template, ['GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER'], true)
                && (int) ($row['damage_reduction_percent'] ?? 0) <= 0
            ) {
                $problems[] = sprintf('job_id=%d %s: memoは被ダメ軽減を示しますが軽減効果が設定されていません', $jobId, $name);
            }

            if (preg_match('/被ダメ[^%\d]{0,6}(\d+)\s*%\s*軽減/u', $memo, $matches)
                && ! in_array($template, ['GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER'], true)
            ) {
                $expectedReduction = (int) $matches[1];
                $actualReduction = (int) ($row['damage_reduction_percent'] ?? 0);
                if ($actualReduction !== $expectedReduction) {
                    $problems[] = sprintf(
                        'job_id=%d %s: memoは被ダメ%d%%軽減ですが damage_reduction_percent=%d です',
                        $jobId,
                        $name,
                        $expectedReduction,
                        $actualReduction
                    );
                }
            }

            if (preg_match('/最大HPの?(\d+)%\s*回復|HP(?:を)?(\d+)%\s*回復/u', $memo, $matches)
                && ! in_array($template, ['HEAL', 'HEAL_CLEANSE'], true)
            ) {
                $expectedHeal = (int) (($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? 0));
                $actualHeal = (int) ($row['heal_percent'] ?? 0);
                if ($expectedHeal > 0 && $actualHeal !== $expectedHeal) {
                    $problems[] = sprintf(
                        'job_id=%d %s: memoはHP%d%%回復ですが heal_percent=%d です',
                        $jobId,
                        $name,
                        $expectedHeal,
                        $actualHeal
                    );
                }
            }

            if (preg_match('/反動で?最大HPの?(\d+)%/u', $memo, $matches)) {
                $expectedSelfDamage = (int) $matches[1];
                $actualSelfDamage = (int) ($row['self_damage_percent'] ?? 0);
                if ($actualSelfDamage !== $expectedSelfDamage) {
                    $problems[] = sprintf(
                        'job_id=%d %s: memoは反動最大HP%d%%ダメージですが self_damage_percent=%d です',
                        $jobId,
                        $name,
                        $expectedSelfDamage,
                        $actualSelfDamage
                    );
                }
            }

            if (str_contains($memo, '吸収')
                && ($template !== 'DRAIN' || ((float) ($row['drain_hp_rate'] ?? 0) <= 0 && (int) ($row['mp_recover_percent'] ?? 0) <= 0))
            ) {
                $problems[] = sprintf('job_id=%d %s: memoは吸収を示しますがDRAINまたは吸収効果が設定されていません', $jobId, $name);
            }

            if (in_array($template, self::DAMAGE_REWARD_TEMPLATES, true)) {
                $learnRank = (int) ($row['learn_rank'] ?? 0);
                $expectedBonus = self::DAMAGE_REWARD_BONUS_BY_RANK[$learnRank] ?? null;
                $goldBonus = $row['gold_bonus_percent'] ?? null;
                $dropBonus = $row['drop_bonus_percent'] ?? null;

                if ($expectedBonus === null || (int) $goldBonus !== $expectedBonus || (int) $dropBonus !== $expectedBonus) {
                    $problems[] = sprintf(
                        'job_id=%d %s: %s は learn_rank=%d の報酬補正を Gold/Drop ともに %s%% で明示してください',
                        $jobId,
                        $name,
                        $template,
                        $learnRank,
                        $expectedBonus === null ? '未定義' : (string) $expectedBonus
                    );
                }
            }

            if (in_array($template, self::GOLD_ONLY_DAMAGE_REWARD_TEMPLATES, true)) {
                $learnRank = (int) ($row['learn_rank'] ?? 0);
                $expectedBonus = self::DAMAGE_REWARD_BONUS_BY_RANK[$learnRank] ?? null;
                $goldBonus = $row['gold_bonus_percent'] ?? null;
                $dropBonus = $row['drop_bonus_percent'] ?? 0;

                if ($expectedBonus === null || (int) $goldBonus !== $expectedBonus || (int) $dropBonus !== 0) {
                    $problems[] = sprintf(
                        'job_id=%d %s: %s は learn_rank=%d のGold補正を %s%%、Drop補正を0%%で明示してください',
                        $jobId,
                        $name,
                        $template,
                        $learnRank,
                        $expectedBonus === null ? '未定義' : (string) $expectedBonus
                    );
                }
            }
        }

        return $problems;
    }

    private static function memoMentionsDamage(string $memo): bool
    {
        return self::containsAny($memo, self::DAMAGE_KEYWORDS)
            && ! str_contains($memo, '被ダメージ')
            && ! str_contains($memo, 'ダメージを1回だけ耐え')
            && ! str_contains($memo, 'ダメージを耐え');
    }

    private static function memoMentionsGoldBonus(string $memo): bool
    {
        return self::containsAny($memo, self::GOLD_KEYWORDS)
            && ! str_contains($memo, 'Gold補正なし')
            && ! str_contains($memo, 'GOLD補正なし')
            && ! str_contains($memo, 'gold補正なし');
    }

    private static function memoMentionsDropBonus(string $memo): bool
    {
        return self::containsAny($memo, self::DROP_KEYWORDS)
            && ! str_contains($memo, 'Drop補正なし')
            && ! str_contains($memo, 'DROP補正なし')
            && ! str_contains($memo, 'drop補正なし')
            && ! str_contains($memo, '素材率補正なし')
            && ! str_contains($memo, 'ドロップ補正なし');
    }

    private static function memoMentionsLuckScaling(string $memo): bool
    {
        return str_contains($memo, 'LUK依存多段')
            || str_contains($memo, 'LUKに応じて');
    }

    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function requiredDebuffsForMemo(string $memo): array
    {
        $required = [];
        if (preg_match('/ATK.{0,6}低下/u', $memo)) {
            $required['enemy_atk_down_percent'] = 'ATK';
        }
        if (preg_match('/MAG.{0,6}低下/u', $memo)) {
            $required['enemy_mag_down_percent'] = 'MAG';
        }
        if (preg_match('/SPD.{0,6}低下/u', $memo)) {
            $required['enemy_spd_down_percent'] = 'SPD';
        }
        if (preg_match('/DEF.{0,6}低下|守り.{0,4}低下/u', $memo)) {
            $required['enemy_def_down_percent'] = 'DEF';
        }

        return $required;
    }

    private static function memoMentionsHpRecovery(string $memo): bool
    {
        return (bool) preg_match('/HP.{0,6}回復|最大HPの?\d+%?回復/u', $memo)
            && ! str_contains($memo, 'SPを');
    }
}
