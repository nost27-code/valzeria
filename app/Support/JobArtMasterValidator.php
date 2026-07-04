<?php

namespace App\Support;

class JobArtMasterValidator
{
    private const DAMAGE_KEYWORDS = ['ダメージ'];
    private const GOLD_KEYWORDS = ['Gold', 'GOLD', 'gold'];
    private const DROP_KEYWORDS = ['Drop', 'DROP', 'drop', '素材率', 'ドロップ', '報酬判定'];
    private const DAMAGE_REWARD_TEMPLATES = ['PHYSICAL_DAMAGE_REWARD', 'MAGICAL_DAMAGE_REWARD'];
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
        }

        return $problems;
    }

    private static function memoMentionsDamage(string $memo): bool
    {
        return self::containsAny($memo, self::DAMAGE_KEYWORDS)
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

    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
