<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class JobRankCatalog
{
    public const NORMAL = 'normal';
    public const DEFAULT = 'default';
    public const MIDDLE = 'middle';
    public const ADVANCED = 'advanced';
    public const SUPER = 'super';
    public const CROWN = 'crown';
    public const HERO = 'hero';
    public const LEGEND = 'legend';
    public const MYTH = 'myth';

    private const RANKS = [
        self::NORMAL => ['label' => '基本職', 'short_label' => '基本', 'badge' => 'NORMAL', 'multiplier' => 1.0],
        self::MIDDLE => ['label' => '中級職', 'short_label' => '中級', 'badge' => 'MIDDLE', 'multiplier' => 2.0],
        self::ADVANCED => ['label' => '上級職', 'short_label' => '上級', 'badge' => 'ADVANCE', 'multiplier' => 5.0],
        self::SUPER => ['label' => '超級職', 'short_label' => '超級', 'badge' => 'SUPER', 'multiplier' => 8.0],
        self::CROWN => ['label' => '冠位職', 'short_label' => '冠位', 'badge' => 'CROWN', 'multiplier' => 10.0],
        self::HERO => ['label' => '英雄職', 'short_label' => '英雄', 'badge' => 'HERO', 'multiplier' => 15.0],
        self::LEGEND => ['label' => '伝説職', 'short_label' => '伝説', 'badge' => 'LEGEND', 'multiplier' => 22.0],
        self::MYTH => ['label' => '神話職', 'short_label' => '神話', 'badge' => 'MYTH', 'multiplier' => 30.0],
    ];

    private const HIGH_RANKS = [
        self::SUPER,
        self::CROWN,
        self::HERO,
        self::LEGEND,
        self::MYTH,
    ];

    private const MID_HIGH_RANKS = [
        self::SUPER,
        self::CROWN,
        self::HERO,
    ];

    private const TOP_HIGH_RANKS = [
        self::LEGEND,
        self::MYTH,
    ];

    public static function keys(): array
    {
        return array_keys(self::RANKS);
    }

    public static function rankOptions(): array
    {
        return collect(self::RANKS)
            ->mapWithKeys(fn (array $rank, string $key) => [$key => $rank['short_label']])
            ->all();
    }

    public static function label(?string $rank): string
    {
        return self::RANKS[self::normalize($rank)]['label'];
    }

    public static function shortLabel(?string $rank): string
    {
        return self::RANKS[self::normalize($rank)]['short_label'];
    }

    public static function badge(?string $rank): string
    {
        return self::RANKS[self::normalize($rank)]['badge'];
    }

    public static function jobExpMultiplier(?string $rank): float
    {
        return (float) self::RANKS[self::normalize($rank)]['multiplier'];
    }

    public static function isBasic(?string $rank): bool
    {
        return self::normalize($rank) === self::NORMAL;
    }

    public static function isHighRank(?string $rank): bool
    {
        return in_array(self::normalize($rank), self::HIGH_RANKS, true);
    }

    public static function inheritanceDivisor(?string $rank): int
    {
        return in_array(self::normalize($rank), self::TOP_HIGH_RANKS, true) ? 3 : 2;
    }

    public static function inheritanceRate(?string $rank): float
    {
        $rank = self::normalize($rank);

        if (in_array($rank, self::MID_HIGH_RANKS, true)) {
            return 0.4;
        }

        if (in_array($rank, self::TOP_HIGH_RANKS, true)) {
            return 1 / 3;
        }

        return 0.5;
    }

    public static function inheritanceFractionLabel(?string $rank): string
    {
        $rank = self::normalize($rank);

        if (in_array($rank, self::MID_HIGH_RANKS, true)) {
            return '2/5';
        }

        if (in_array($rank, self::TOP_HIGH_RANKS, true)) {
            return '1/3';
        }

        return '1/2';
    }

    public static function inheritancePercentLabel(?string $rank): string
    {
        $rank = self::normalize($rank);

        if (in_array($rank, self::MID_HIGH_RANKS, true)) {
            return '40%';
        }

        if (in_array($rank, self::TOP_HIGH_RANKS, true)) {
            return '33%';
        }

        return '50%';
    }

    public static function normalize(?string $rank): string
    {
        $rank = (string) ($rank ?: self::NORMAL);

        return $rank === self::DEFAULT || ! array_key_exists($rank, self::RANKS)
            ? self::NORMAL
            : $rank;
    }

    public static function orderByRank(Builder $query, string $column = 'rank'): Builder
    {
        $cases = collect(self::keys())
            ->map(fn (string $rank, int $index) => "WHEN '{$rank}' THEN {$index}")
            ->implode(' ');

        return $query->orderByRaw("CASE {$column} WHEN 'default' THEN 0 {$cases} ELSE 99 END");
    }
}
