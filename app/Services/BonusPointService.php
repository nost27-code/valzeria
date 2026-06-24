<?php

namespace App\Services;

use App\Models\Character;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class BonusPointService
{
    private const POINTS_PER_LEVEL = 1;

    private const STATS = [
        'hp' => ['label' => 'HP', 'column' => 'hp_base', 'gain' => 10],
        'mp' => ['label' => 'SP', 'column' => 'mp_base', 'gain' => 5],
        'str' => ['label' => '攻撃', 'column' => 'attack_base', 'gain' => 1],
        'def' => ['label' => '防御', 'column' => 'defense_base', 'gain' => 1],
        'agi' => ['label' => '敏捷', 'column' => 'speed_base', 'gain' => 1],
        'mag' => ['label' => '魔力', 'column' => 'magic_base', 'gain' => 1],
        'spr' => ['label' => '精神', 'column' => 'spirit_base', 'gain' => 1],
        'luk' => ['label' => '運', 'column' => 'luck_base', 'gain' => 1],
    ];

    public function pointsPerLevel(): int
    {
        return self::POINTS_PER_LEVEL;
    }

    public function statOptions(): array
    {
        return self::STATS;
    }

    public function allocate(Character $character, string $stat, int $points): array
    {
        $result = $this->allocateMany($character, [$stat => $points]);

        return [
            'label' => self::STATS[$stat]['label'] ?? '能力',
            'gain' => $result['applied'][$stat]['gain'] ?? 0,
            'remaining' => $result['remaining'],
        ];
    }

    public function allocateMany(Character $character, array $allocations): array
    {
        $normalized = [];
        foreach ($allocations as $stat => $points) {
            if (!array_key_exists($stat, self::STATS)) {
                throw new InvalidArgumentException('割り振り先が不正です。');
            }

            $points = (int) $points;
            if ($points < 0) {
                throw new InvalidArgumentException('割り振るポイントが不正です。');
            }

            if ($points > 0) {
                $normalized[$stat] = $points;
            }
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('割り振るポイントを指定してください。');
        }

        $totalPoints = array_sum($normalized);

        return DB::transaction(function () use ($character, $normalized, $totalPoints) {
            $locked = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            $available = (int) ($locked->bonus_points ?? 0);
            if ($available < $totalPoints) {
                throw new RuntimeException('ボーナスポイントが不足しています。');
            }

            $applied = [];
            foreach ($normalized as $stat => $points) {
                $config = self::STATS[$stat];
                $column = $config['column'];
                $gain = $config['gain'] * $points;

                $locked->{$column} = (int) $locked->{$column} + $gain;

                if ($stat === 'hp') {
                    $locked->current_hp = (int) $locked->current_hp + $gain;
                } elseif ($stat === 'mp') {
                    $locked->current_mp = (int) $locked->current_mp + $gain;
                }

                $applied[$stat] = [
                    'label' => $config['label'],
                    'points' => $points,
                    'gain' => $gain,
                ];
            }

            $locked->bonus_points = $available - $totalPoints;
            $locked->save();

            return [
                'applied' => $applied,
                'spent' => $totalPoints,
                'remaining' => (int) $locked->bonus_points,
            ];
        });
    }
}
