<?php

namespace App\Support;

/**
 * weapon_rank='SPECIAL'（星樹の塔報酬武器など、進化ラインに属さない特別武器）を
 * 「未装備相当」に落とさず、実効ランクとして正しく比較できるようにする。
 *
 * items.display_rank には「A+相当」「S+相当」「SS+相当」のような文字列が
 * 星樹の塔武器生成時（config/star_tree_tower_rewards.php）に設定されている。
 * この文字列先頭の英字部分を実効ランクとして採用する。
 */
class WeaponRankResolver
{
    /**
     * @var array<string, int>
     */
    private const ORDER = [
        'G' => 0, 'F' => 1, 'E' => 2, 'D' => 3, 'C' => 4,
        'B' => 5, 'A' => 6, 'S' => 7, 'SS' => 8, 'SSS' => 9, 'EPIC' => 10,
    ];

    /**
     * weapon_rank と display_rank から、ランク比較に使える実効ランクを返す。
     * SPECIAL以外はweapon_rankをそのまま大文字化して返す。
     */
    public static function effectiveRank(?string $weaponRank, ?string $displayRank = null): ?string
    {
        $weaponRank = $weaponRank ? strtoupper(trim($weaponRank)) : null;

        if ($weaponRank !== 'SPECIAL') {
            return $weaponRank;
        }

        $displayRank = (string) ($displayRank ?? '');
        if (preg_match('/^([A-Za-z]+)/u', $displayRank, $matches) === 1) {
            $candidate = strtoupper($matches[1]);
            if (array_key_exists($candidate, self::ORDER)) {
                return $candidate;
            }
        }

        // display_rankから読み取れない場合は比較対象外(未装備相当)として扱う。
        return null;
    }

    /**
     * ランクの序列インデックス。未知のランクは0(最弱扱い)を返す。
     */
    public static function order(?string $rank): int
    {
        if ($rank === null) {
            return -1; // 実効ランク不明は「未装備」より弱いものとして扱わない特別値
        }

        return self::ORDER[strtoupper($rank)] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public static function orderMap(): array
    {
        return self::ORDER;
    }
}
