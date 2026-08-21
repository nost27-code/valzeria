<?php

namespace App\Services\Battle;

use App\Support\PlayerStatLabel;

final class BattleStatChangeLogFormatter
{
    /**
     * @param  list<array{label: string, before: int, after: int}>  $changes
     */
    public static function fromValues(string $actorName, array $changes, bool $isBuff): string
    {
        $percentages = [];
        foreach ($changes as $change) {
            $before = (int) $change['before'];
            $after = (int) $change['after'];
            if ($before === $after) {
                continue;
            }

            $percentages[] = [
                'label' => (string) $change['label'],
                'percent' => $before > 0
                    ? max(1, (int) round((abs($after - $before) / $before) * 100))
                    : 0,
            ];
        }

        if ($percentages === []) {
            return self::noChange($actorName, $isBuff);
        }

        return self::fromPercentages($actorName, $percentages, $isBuff);
    }

    /**
     * @param  list<array{label: string, percent: int|float}>  $changes
     */
    public static function fromPercentages(
        string $actorName,
        array $changes,
        bool $isBuff,
        ?string $duration = null,
    ): string {
        $descriptions = [];
        foreach ($changes as $change) {
            $percent = abs((float) $change['percent']);
            if ($percent <= 0.0) {
                continue;
            }

            $descriptions[] = e(self::statLabel((string) $change['label']))
                .'が'.self::formatPercent($percent).'%';
        }

        if ($descriptions === []) {
            return self::noChange($actorName, $isBuff);
        }

        $color = $isBuff ? 'text-indigo-600' : 'text-violet-700';
        $direction = $isBuff ? 'アップ' : 'ダウン';
        $suffix = $duration !== null && $duration !== '' ? '（'.e($duration).'）' : '';

        return '<span data-battle-stat-change class="'.$color.' font-bold">'
            .e($actorName).' の'.implode('、', $descriptions).$direction.'した！'.$suffix
            .'</span>';
    }

    /** @param array<string, float> $modifiers */
    public static function fromModifiers(
        string $actorName,
        array $modifiers,
        ?string $duration = null,
    ): string {
        $buffs = [];
        $debuffs = [];
        foreach ($modifiers as $stat => $rate) {
            if ($rate > 0.0) {
                $buffs[] = ['label' => $stat, 'percent' => $rate * 100];
            } elseif ($rate < 0.0) {
                $debuffs[] = ['label' => $stat, 'percent' => abs($rate) * 100];
            }
        }

        if ($buffs !== [] && $debuffs === []) {
            return self::fromPercentages($actorName, $buffs, true, $duration);
        }
        if ($debuffs !== [] && $buffs === []) {
            return self::fromPercentages($actorName, $debuffs, false, $duration);
        }

        $parts = [];
        if ($buffs !== []) {
            $parts[] = self::plainClause($buffs, 'アップ');
        }
        if ($debuffs !== []) {
            $parts[] = self::plainClause($debuffs, 'ダウン');
        }
        $suffix = $duration !== null && $duration !== '' ? '（'.e($duration).'）' : '';

        return '<span data-battle-stat-change class="text-indigo-600 font-bold">'
            .e($actorName).' の'.implode('、', $parts).'！'.$suffix
            .'</span>';
    }

    private static function noChange(string $actorName, bool $isBuff): string
    {
        $color = $isBuff ? 'text-indigo-600' : 'text-violet-700';
        $verb = $isBuff ? '強化' : '弱体化';

        return '<span data-battle-stat-change class="'.$color.' font-bold">'.e($actorName).' はこれ以上'.$verb.'できない！</span>';
    }

    /** @param list<array{label: string, percent: int|float}> $changes */
    private static function plainClause(array $changes, string $direction): string
    {
        $descriptions = array_map(
            static fn (array $change): string => e(self::statLabel((string) $change['label']))
                .'が'.self::formatPercent(abs((float) $change['percent'])).'%',
            $changes,
        );

        return implode('、', $descriptions).$direction.'した';
    }

    private static function statLabel(string $label): string
    {
        return PlayerStatLabel::for($label);
    }

    private static function formatPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.');
    }
}
