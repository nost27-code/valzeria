<?php

namespace App\Services;

final class JobArtStatBuffLogFormatter
{
    /** @var array<string, string> */
    private const PLAYER_STAT_LABELS = [
        'str' => '攻撃',
        'def' => '防御',
        'mag' => '魔力',
        'spr' => '精神',
        'agi' => '敏捷',
        'luk' => '運',
    ];

    /** @param array<string, float> $modifiers */
    public function formatIncrease(
        string $actorName,
        array $modifiers,
        int $duration,
        string $durationUnit,
    ): ?string {
        $changes = [];
        foreach ($modifiers as $stat => $rate) {
            $label = self::PLAYER_STAT_LABELS[$stat] ?? null;
            if ($label === null || $rate <= 0.0) {
                continue;
            }

            $percent = rtrim(rtrim(number_format($rate * 100, 1, '.', ''), '0'), '.');
            $changes[] = $label.'が'.$percent.'%';
        }

        if ($changes === []) {
            return null;
        }

        return '<span class="text-indigo-700 font-bold">'
            .e($actorName).' の'.implode('、', $changes).'アップした！（'.max(1, $duration).$durationUnit.'）</span>';
    }
}
