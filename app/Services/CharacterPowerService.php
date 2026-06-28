<?php

namespace App\Services;

use App\Models\Area;
use App\Models\City;
use App\Services\Enemy\EnemyStatGenerationService;
use Illuminate\Support\Collection;

class CharacterPowerService
{
    private const NORMAL_ENEMY_ROLES = ['雑魚', 'やや強い'];

    public function fromFinalStats(array $stats): int
    {
        return $this->fromNormalizedStats([
            'max_hp' => (int) ($stats['max_hp'] ?? 0),
            'max_mp' => (int) ($stats['max_mp'] ?? 0),
            'str' => (int) ($stats['str'] ?? 0),
            'def' => (int) ($stats['def'] ?? 0),
            'agi' => (int) ($stats['agi'] ?? 0),
            'mag' => (int) ($stats['mag'] ?? 0),
            'spr' => (int) ($stats['spr'] ?? 0),
            'luk' => (int) ($stats['luk'] ?? 0),
        ]);
    }

    public function fromEnemyStats(array $stats): int
    {
        return $this->fromNormalizedStats([
            'max_hp' => (int) ($stats['max_hp'] ?? $stats['hp'] ?? 0),
            'max_mp' => (int) ($stats['max_mp'] ?? 0),
            'str' => (int) ($stats['str'] ?? $stats['attack'] ?? 0),
            'def' => (int) ($stats['def'] ?? $stats['defense'] ?? 0),
            'agi' => (int) ($stats['agi'] ?? $stats['speed'] ?? 0),
            'mag' => (int) ($stats['mag'] ?? $stats['magic'] ?? 0),
            'spr' => (int) ($stats['spr'] ?? $stats['spirit'] ?? 0),
            'luk' => (int) ($stats['luk'] ?? $stats['luck'] ?? 0),
        ]);
    }

    /**
     * @return array{min:int,max:int}
     */
    public function openingRecommendedRangeForLevels(int $minLevel, int $maxLevel): array
    {
        $range = $this->recommendedRangeForLevels($minLevel, $maxLevel);
        return $this->scaledRange($range, 1.8);
    }

    /**
     * @return array{min:int,max:int}
     */
    public function recommendedRangeForArea(Area $area): array
    {
        $powers = $this->normalEnemyPowers($area);
        if ($powers->isNotEmpty()) {
            return [
                'min' => (int) $powers->min(),
                'max' => (int) $powers->max(),
            ];
        }

        return $this->recommendedRangeForLevels(
            (int) ($area->recommended_level_min ?? $area->recommended_level ?? 1),
            (int) ($area->recommended_level_max ?? $area->recommended_level_min ?? $area->recommended_level ?? 1)
        );
    }

    /**
     * @return array{min:int,max:int}
     */
    public function openingRecommendedRangeForArea(Area $area): array
    {
        return $this->scaledRange($this->recommendedRangeForArea($area), 1.8);
    }

    /**
     * @return array{min:int,max:int}
     */
    public function recommendedRangeForCity(City $city): array
    {
        $city->loadMissing('areas.enemies');

        $powers = $city->areas
            ->flatMap(fn (Area $area) => $this->normalEnemyPowers($area));

        if ($powers->isNotEmpty()) {
            return [
                'min' => (int) $powers->min(),
                'max' => (int) $powers->max(),
            ];
        }

        return $this->recommendedRangeForLevels(
            (int) ($city->recommended_level_min ?? 1),
            (int) ($city->recommended_level_max ?? $city->recommended_level_min ?? 1)
        );
    }

    /**
     * @return array{min:int,max:int}
     */
    public function openingRecommendedRangeForCity(City $city): array
    {
        return $this->scaledRange($this->recommendedRangeForCity($city), 1.8);
    }

    /**
     * @param  array{min:int,max:int}  $range
     * @return array{min:int,max:int}
     */
    private function scaledRange(array $range, float $scale): array
    {
        return [
            'min' => (int) round($range['min'] * $scale),
            'max' => (int) round($range['max'] * $scale),
        ];
    }

    /**
     * @return array{min:int,max:int}
     */
    public function recommendedRangeForLevels(int $minLevel, int $maxLevel): array
    {
        $generator = app(EnemyStatGenerationService::class);
        $minLevel = max(1, $minLevel);
        $maxLevel = max($minLevel, $maxLevel);

        $minPower = $this->fromEnemyStats($generator->generate($minLevel)['stats']);
        $maxPower = $this->fromEnemyStats($generator->generate($maxLevel)['stats']);

        return [
            'min' => min($minPower, $maxPower),
            'max' => max($minPower, $maxPower),
        ];
    }

    /**
     * @param  array{min:int,max:int}  $range
     */
    public function formatRange(array $range): string
    {
        $min = (int) ($range['min'] ?? 0);
        $max = (int) ($range['max'] ?? $min);

        return $min === $max
            ? number_format($min)
            : number_format($min) . '〜' . number_format($max);
    }

    /**
     * @return Collection<int, int>
     */
    private function normalEnemyPowers(Area $area): Collection
    {
        $area->loadMissing('enemies');

        return $area->enemies
            ->filter(fn ($enemy) => ! (bool) $enemy->is_boss)
            ->filter(fn ($enemy) => in_array((string) ($enemy->role ?? ''), self::NORMAL_ENEMY_ROLES, true))
            ->map(fn ($enemy) => $this->fromEnemyStats([
                'max_hp' => (int) $enemy->max_hp,
                'max_mp' => 0,
                'str' => (int) $enemy->str,
                'def' => (int) $enemy->def,
                'agi' => (int) $enemy->agi,
                'mag' => (int) $enemy->mag,
                'spr' => (int) ($enemy->spr ?? 0),
                'luk' => (int) $enemy->luk,
            ]))
            ->values();
    }

    private function fromNormalizedStats(array $stats): int
    {
        $maxHp = (int) $stats['max_hp'];
        $maxMp = (int) $stats['max_mp'];
        $str   = (int) $stats['str'];
        $mag   = (int) $stats['mag'];
        $def   = (int) $stats['def'];
        $spr   = (int) $stats['spr'];
        $agi   = (int) $stats['agi'];
        $luk   = (int) $stats['luk'];

        // 1. 火力
        $mainOffense = max($str, $mag);

        // AGI補正は後半まで伸びを感じられるように、緩やかに上限へ近づける
        $agiMultiplier = 1.0 + min(0.35, $agi / 2500.0);
        $offenseScore = $mainOffense * $agiMultiplier;

        // 2. 耐久
        // 弱い防御面を重く見る方針は維持
        $minDef = min($def, $spr);
        $maxDef = max($def, $spr);
        $balancedDefense = ($minDef * 1.5) + ($maxDef * 0.5);

        // 防御係数も少し抑える
        $defenseScore = $maxHp * (1.0 + ($balancedDefense / 700.0));

        // 3. 補助
        // MPとLUKは補助扱い。LUKは上限を付ける。
        $effectiveLuk = min($luk, 150);

        $utilityScore =
            ($maxMp * 0.35)
          + ($effectiveLuk * 1.0);

        // 4. 総合
        $totalScore = sqrt($offenseScore * $defenseScore * 10) + $utilityScore;

        return max(1, (int) round($totalScore));
    }
}
