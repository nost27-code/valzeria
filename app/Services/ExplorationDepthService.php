<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;

class ExplorationDepthService
{
    private const TIERS = [
        [
            'key' => 'surface',
            'label' => '表層',
            'message' => 'いつもの探索路を進んでいます。',
            'min_point' => 0,
            'min_danger' => 0,
            'level_offset_min' => 0,
            'level_offset_max' => 0,
            'overlay' => 0,
        ],
        [
            'key' => 'inner',
            'label' => '深部',
            'message' => '空気が少し重くなってきた……深部に入りました。',
            'min_point' => 150,
            'min_danger' => 10,
            'level_offset_min' => 5,
            'level_offset_max' => 5,
            'overlay' => 15,
        ],
        [
            'key' => 'deep',
            'label' => '深層',
            'message' => '周囲の景色が変わった……深層に到達しました。',
            'min_point' => 300,
            'min_danger' => 25,
            'level_offset_min' => 15,
            'level_offset_max' => 15,
            'overlay' => 30,
        ],
        [
            'key' => 'deepest',
            'label' => '最深層',
            'message' => '足元から冷たい気配が立ち上る……最深層の入口が近い。',
            'min_point' => 800,
            'min_danger' => 50,
            'level_offset_min' => 25,
            'level_offset_max' => 25,
            'overlay' => 45,
        ],
        [
            'key' => 'otherworld',
            'label' => '異界層',
            'message' => '空間が歪んでいる……異界層の気配を感じます。',
            'min_point' => 1200,
            'min_danger' => 75,
            'level_offset_min' => 40,
            'level_offset_max' => 40,
            'overlay' => 60,
        ],
    ];

    public function tierFor(int $explorationPoint, int $dangerRate): array
    {
        $matched = self::TIERS[0];

        foreach (self::TIERS as $tier) {
            if ($explorationPoint >= $tier['min_point'] && $dangerRate >= $tier['min_danger']) {
                $matched = $tier;
            }
        }

        return $matched;
    }

    public function tierByKey(string $key): ?array
    {
        foreach (self::TIERS as $tier) {
            if ($tier['key'] === $key) {
                return $tier;
            }
        }

        return null;
    }

    public function activeTierFor(Character $character, Area $area, int $explorationPoint, int $dangerRate): array
    {
        $state = app(ExplorationStateService::class)->currentFor($character);
        if (!$state || (int) $state->area_id !== (int) $area->id) {
            return self::TIERS[0];
        }

        // 到達済みの深度は中断・再開やセッション切れをまたいでも失われないよう、
        // セッションではなく character_exploration_states.depth_tier に永続化して参照する。
        $storedKey = (string) ($state->depth_tier ?? 'surface');
        $stored = $this->tierByKey($storedKey) ?? self::TIERS[0];

        // 探索度がその階層の到達条件を満たしていない場合（データ不整合時の保険）は、
        // 実際の探索度から見て妥当な階層まで引き下げる。
        if ($explorationPoint < (int) ($stored['min_point'] ?? 0)) {
            return $this->tierFor($explorationPoint, $dangerRate);
        }

        return $stored;
    }

    public function nextReachableTierFor(Character $character, Area $area, int $explorationPoint, int $dangerRate): ?array
    {
        $active = $this->activeTierFor($character, $area, $explorationPoint, $dangerRate);
        $next = self::TIERS[$this->tierIndex($active['key'] ?? 'surface') + 1] ?? null;
        if (!$next) {
            return null;
        }

        $next['remaining_point'] = max(0, (int) $next['min_point'] - $explorationPoint);
        $next['remaining_danger'] = max(0, (int) $next['min_danger'] - $dangerRate);

        if ($next['remaining_point'] <= 0 && $next['remaining_danger'] <= 0 && $this->gateHandled($character, $area, (string) $next['key'])) {
            return null;
        }

        return $next;
    }

    public function currentGateFor(Character $character, Area $area, int $explorationPoint, int $dangerRate): ?array
    {
        $next = $this->nextReachableTierFor($character, $area, $explorationPoint, $dangerRate);
        if (!$next) {
            return null;
        }

        if (($next['remaining_point'] ?? 0) > 0 || ($next['remaining_danger'] ?? 0) > 0) {
            return null;
        }

        return $next;
    }

    public function markEntered(Character $character, int $areaId, string $depthKey): void
    {
        if ($depthKey === '' || $depthKey === 'surface') {
            return;
        }

        $stateService = app(ExplorationStateService::class);
        $state = $stateService->currentFor($character);
        if (!$state || (int) $state->area_id !== $areaId) {
            return;
        }

        $currentKey = (string) ($state->depth_tier ?? 'surface');
        if ($currentKey === $depthKey) {
            return;
        }

        // 到達済み階層は character_exploration_states.depth_tier に永続化し、
        // 中断・再開やセッション切れがあっても引き返し時に表層まで戻らないようにする。
        if ($this->tierIndex($depthKey) > $this->tierIndex($currentKey)) {
            $state->forceFill(['depth_tier' => $depthKey])->save();
            $stateService->resetDangerForDepthEntrance($character, $areaId);
        }
    }

    public function markGateHandled(Character $character, int $areaId, string $depthKey): void
    {
        if ($depthKey !== '' && $depthKey !== 'surface') {
            session([$this->handledSessionKey($character, $areaId, $depthKey) => true]);
        }
    }

    public function gateHandled(Character $character, Area $area, string $depthKey): bool
    {
        if ($depthKey === '' || $depthKey === 'surface') {
            return false;
        }

        return (bool) session()->get($this->handledSessionKey($character, (int) $area->id, $depthKey), false);
    }

    private function handledSessionKey(Character $character, int $areaId, string $depthKey): string
    {
        $state = app(ExplorationStateService::class)->currentFor($character);
        $startedAt = $state?->started_at ? $state->started_at->timestamp : 0;

        return "depth_gate_ack:{$character->id}:{$areaId}:{$depthKey}:{$startedAt}";
    }

    public function minimumDangerForPoint(int $explorationPoint): int
    {
        $minimumDanger = 0;

        foreach (self::TIERS as $tier) {
            if ($explorationPoint >= $tier['min_point']) {
                $minimumDanger = max($minimumDanger, (int) $tier['min_danger']);
            }
        }

        return $minimumDanger;
    }

    public function enemyPowerBonusForTier(array $tier): float
    {
        return match ($tier['key'] ?? 'surface') {
            'inner' => 0.25,
            'deep' => 0.75,
            'deepest' => 1.75,
            'otherworld' => 3.0,
            default => 0.0,
        };
    }

    public function enemyHpMultiplierForTier(array $tier): float
    {
        return match ($tier['key'] ?? 'surface') {
            'inner' => 1.25,
            'deep' => 1.75,
            'deepest' => 2.5,
            'otherworld' => 3.5,
            default => 1.0,
        };
    }

    public function expRewardMultiplierForTier(array $tier): float
    {
        return match ($tier['key'] ?? 'surface') {
            'deep' => 1.5,
            'deepest' => 3.0,
            'otherworld' => 5.0,
            default => 1.0,
        };
    }

    public function nextTierFor(int $explorationPoint, int $dangerRate): ?array
    {
        foreach (self::TIERS as $tier) {
            if ($explorationPoint < $tier['min_point'] || $dangerRate < $tier['min_danger']) {
                $tier['remaining_point'] = max(0, $tier['min_point'] - $explorationPoint);
                $tier['remaining_danger'] = max(0, $tier['min_danger'] - $dangerRate);

                return $tier;
            }
        }

        return null;
    }

    public function crossedTiers(int $beforePoint, int $beforeDanger, int $afterPoint, int $afterDanger): array
    {
        $beforeIndex = $this->tierIndex($this->tierFor($beforePoint, $beforeDanger)['key']);
        $afterIndex = $this->tierIndex($this->tierFor($afterPoint, $afterDanger)['key']);

        if ($afterIndex <= $beforeIndex) {
            return [];
        }

        return array_values(array_filter(
            self::TIERS,
            fn (array $tier): bool => $this->tierIndex($tier['key']) > $beforeIndex
                && $this->tierIndex($tier['key']) <= $afterIndex
        ));
    }

    public function summary(Character $character, Area $area, int $explorationPoint, int $dangerRate): array
    {
        $current = $this->activeTierFor($character, $area, $explorationPoint, $dangerRate);
        $next = $this->nextReachableTierFor($character, $area, $explorationPoint, $dangerRate);
        $recommended = $this->recommendedLevelRange($area, $current);
        $characterLevel = (int) ($character->level ?? 1);

        return [
            'current' => $current,
            'next' => $next,
            'recommended_level_min' => $recommended['min'],
            'recommended_level_max' => $recommended['max'],
            'is_overleveled_warning' => $recommended['min'] > 0 && $characterLevel + 10 < $recommended['min'],
        ];
    }

    public function targetLevelForTier(Area $area, array $tier): int
    {
        $recommended = $this->recommendedLevelRange($area, $tier);

        return max(1, (int) round(($recommended['min'] + $recommended['max']) / 2));
    }

    public function tierIndexForKey(string $key): int
    {
        return $this->tierIndex($key);
    }

    public function recommendedLevelRangeForTier(Area $area, array $tier): array
    {
        return $this->recommendedLevelRange($area, $tier);
    }

    private function recommendedLevelRange(Area $area, array $tier): array
    {
        $baseMin = (int) ($area->recommended_level_min ?? $area->recommended_level ?? 1);
        $baseMax = (int) ($area->recommended_level_max ?? $baseMin);

        return [
            'min' => $baseMin + (int) $tier['level_offset_min'],
            'max' => $baseMax + (int) $tier['level_offset_max'],
        ];
    }

    private function tierIndex(string $key): int
    {
        foreach (self::TIERS as $index => $tier) {
            if ($tier['key'] === $key) {
                return $index;
            }
        }

        return 0;
    }
}
