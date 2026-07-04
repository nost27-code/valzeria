<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\CharacterExplorationState;
use App\Models\CharacterItem;
use App\Models\Enemy;
use App\Models\ExplorationLootLog;
use App\Models\Material;

class ExplorationStateService
{
    public function getOrStart(Character $character, int $areaId): CharacterExplorationState
    {
        $state = CharacterExplorationState::firstOrCreate(
            ['character_id' => $character->id],
            [
                'area_id' => $areaId,
                'exploration_point' => 0,
                'chain_count' => 0,
                'danger_rate' => 0,
                'last_treasure_band' => 0,
                'treasure_found_count' => 0,
                'secret_realm_found_count' => 0,
                'dungeon_lord_encountered' => false,
                'valmon_material_found' => false,
                'valmon_heal_used' => false,
                'rescue_insurance_enabled' => false,
                'started_at' => now(),
            ]
        );

        if ((int) $state->area_id !== $areaId) {
            $state->forceFill([
                'area_id' => $areaId,
                'exploration_point' => 0,
                'chain_count' => 0,
                'danger_rate' => 0,
                'last_treasure_band' => 0,
                'treasure_found_count' => 0,
                'secret_realm_found_count' => 0,
                'dungeon_lord_encountered' => false,
                'valmon_material_found' => false,
                'valmon_heal_used' => false,
                'rescue_insurance_enabled' => false,
                'started_at' => now(),
            ])->save();

            app(ExplorationItemService::class)->reset($character);
        }

        return $state;
    }

    public function currentFor(Character $character): ?CharacterExplorationState
    {
        return CharacterExplorationState::where('character_id', $character->id)->first();
    }

    public function hasActiveExploration(Character $character): bool
    {
        $state = $this->currentFor($character);

        if (! $state || ! $state->area_id) {
            return false;
        }

        return (int) ($state->exploration_point ?? 0) > 0
            || (int) ($state->chain_count ?? 0) > 0
            || (int) ($state->danger_rate ?? 0) > 0;
    }

    public function recordVictory(Character $character, Enemy $enemy, int $valueMultiplier = 1): array
    {
        $state = $this->getOrStart($character, (int) $enemy->area_id);
        $beforePoint = (int) $state->exploration_point;
        $beforeChain = (int) $state->chain_count;
        $beforeDanger = (int) ($state->danger_rate ?? 0);
        $valueMultiplier = max(1, $valueMultiplier);
        $addedPoint = $this->pointForEnemy($enemy) * $valueMultiplier;
        $afterPoint = $beforePoint + $addedPoint;
        $dangerResult = $this->rollDangerIncreaseRepeated($character, $enemy, $beforeDanger, $valueMultiplier);

        $state->exploration_point = $afterPoint;
        $state->chain_count = $beforeChain + 1;
        $state->danger_rate = $dangerResult['after'];
        $state->save();

        // 深度到達の告知は「これから提示できるゲート（引き返し等で確定ブロックされていない階層）」にのみ絞る。
        // 生の探索度/危険度の閾値だけで判定すると、一度引き返した階層より先の層に対しても
        // 「〜に到達しました」という案内が出てしまい、実際には入っていない層の到達を騙ることになる。
        $depthService = app(ExplorationDepthService::class);
        $area = $enemy->relationLoaded('area') ? $enemy->area : $enemy->area()->first();
        $depthTransitions = [];
        if ($area) {
            $gateBefore = $depthService->currentGateFor($character, $area, $beforePoint, $beforeDanger);
            $gateAfter = $depthService->currentGateFor($character, $area, (int) $state->exploration_point, (int) $state->danger_rate);
            if ($gateAfter && (string) ($gateBefore['key'] ?? '') !== (string) ($gateAfter['key'] ?? '')) {
                $depthTransitions = [$gateAfter];
            }
        }

        return [
            'state' => $state->fresh(),
            'added_point' => $addedPoint,
            'before_point' => $beforePoint,
            'before_chain' => $beforeChain,
            'danger' => $dangerResult,
            'milestones' => $this->crossedMilestones($beforePoint, (int) $state->exploration_point),
            'depth_transitions' => $depthTransitions,
            'next_milestone' => $this->nextMilestone((int) $state->exploration_point),
        ];
    }

    public function recordMaterialLoot(Character $character, Enemy $enemy, Material $material, int $quantity = 1): void
    {
        if ($quantity <= 0 || $enemy->is_boss) {
            return;
        }

        $this->getOrStart($character, (int) $enemy->area_id);

        ExplorationLootLog::create([
            'character_id' => $character->id,
            'area_id' => $enemy->area_id,
            'material_id' => $material->id,
            'quantity' => $quantity,
            'penalized' => false,
        ]);
    }

    public function recordItemLoot(Character $character, Enemy $enemy, CharacterItem $characterItem): void
    {
        if ($enemy->is_boss) {
            return;
        }

        $this->getOrStart($character, (int) $enemy->area_id);

        ExplorationLootLog::create([
            'character_id' => $character->id,
            'area_id' => $enemy->area_id,
            'character_item_id' => $characterItem->id,
            'quantity' => 1,
            'penalized' => false,
        ]);
    }

    public function currentLootSummary(Character $character, int $areaId): array
    {
        $state = $this->currentFor($character);
        if (!$state || (int) $state->area_id !== $areaId || !$state->started_at) {
            return $this->emptyLootSummary();
        }

        $logs = ExplorationLootLog::with(['material', 'characterItem.item'])
            ->where('character_id', $character->id)
            ->where('area_id', $areaId)
            ->where('penalized', false)
            ->where('created_at', '>=', $state->started_at)
            ->get();

        if ($logs->isEmpty()) {
            return $this->emptyLootSummary();
        }

        $materials = $logs
            ->filter(fn ($log) => $log->material_id !== null)
            ->groupBy('material_id')
            ->map(function ($materialLogs) {
                $quantity = (int) $materialLogs->sum('quantity');
                $material = $materialLogs->first()->material;

                return [
                    'name' => $material?->displayName() ?? '不明な素材',
                    'rarity' => strtoupper((string) ($material?->rarity ?? '')),
                    'is_sr' => strtoupper((string) ($material?->rarity ?? '')) === 'SR',
                    'is_sell_treasure' => (bool) $material?->isSellTreasure(),
                    'quantity' => $quantity,
                    'risk_quantity' => intdiv($quantity, 2),
                ];
            })
            ->sortBy('name')
            ->values();

        $items = $logs
            ->filter(fn ($log) => $log->character_item_id !== null)
            ->map(function ($log) use ($character) {
                $characterItem = $log->characterItem;
                $item = $characterItem?->item;

                return [
                    'name' => $characterItem?->displayName() ?? '不明な装備',
                    'rank' => $item?->weapon_rank ?? $item?->armor_rank ?? $item?->accessory_rank ?? $item?->rarity,
                    'type' => $item?->type ?? 'item',
                    'can_lose' => $characterItem
                        && (int) $characterItem->character_id === (int) $character->id
                        && !$characterItem->is_equipped
                        && !$characterItem->is_locked,
                ];
            })
            ->values();

        $materialTotal = $materials->sum('quantity');
        $itemTotal = $items->count();
        $riskItemTotal = intdiv($items->where('can_lose', true)->count(), 2);

        return [
            'materials' => $materials->all(),
            'items' => $items->all(),
            'material_total' => $materialTotal,
            'item_total' => $itemTotal,
            'risk_material_total' => intdiv($materialTotal, 2),
            'risk_item_total' => $riskItemTotal,
            'risk_total' => intdiv($materialTotal, 2) + $riskItemTotal,
        ];
    }

    private function emptyLootSummary(): array
    {
        return [
            'materials' => [],
            'items' => [],
            'material_total' => 0,
            'item_total' => 0,
            'risk_material_total' => 0,
            'risk_item_total' => 0,
            'risk_total' => 0,
        ];
    }

    public function applyDefeatMaterialPenalty(Character $character, int $areaId, int $lossPercent = 50): array
    {
        $state = $this->currentFor($character);
        if (!$state || (int) $state->area_id !== $areaId || !$state->started_at) {
            return ['total_lost' => 0, 'materials' => [], 'items' => []];
        }

        $logs = ExplorationLootLog::with(['material', 'characterItem.item'])
            ->where('character_id', $character->id)
            ->where('area_id', $areaId)
            ->where('penalized', false)
            ->where('created_at', '>=', $state->started_at)
            ->get();

        if ($logs->isEmpty()) {
            return ['total_lost' => 0, 'materials' => [], 'items' => []];
        }

        $lostMaterials = [];
        $lostItems = [];
        $materialLostTotal = 0;
        $itemLostTotal = 0;
        $materialLogs = $logs->filter(fn ($log) => $log->material_id !== null);
        $itemLogs = $logs->filter(function ($log) use ($character) {
            $characterItem = $log->characterItem;

            return $log->character_item_id !== null
                && $characterItem
                && (int) $characterItem->character_id === (int) $character->id
                && !$characterItem->is_equipped
                && !$characterItem->is_locked;
        });
        $materialLossRemaining = $this->lossCount((int) $materialLogs->sum('quantity'), $lossPercent);
        $itemLossRemaining = $this->lossCount($itemLogs->count(), $lossPercent);

        foreach ($materialLogs->groupBy('material_id')->sortByDesc(fn ($rows) => $rows->sum('quantity')) as $materialId => $groupedLogs) {
            if ($materialLossRemaining <= 0) {
                break;
            }

            $owned = CharacterMaterial::where('character_id', $character->id)
                ->where('material_id', $materialId)
                ->first();
            if (!$owned || (int) $owned->quantity <= 0) {
                continue;
            }

            $actualLost = min($materialLossRemaining, (int) $groupedLogs->sum('quantity'), (int) $owned->quantity);
            if ($actualLost <= 0) {
                continue;
            }

            $owned->decrement('quantity', $actualLost);

            $material = $groupedLogs->first()->material;
            $lostMaterials[] = [
                'material_id' => (int) $materialId,
                'name' => $material?->displayName() ?? '不明な素材',
                'quantity' => $actualLost,
            ];
            $materialLostTotal += $actualLost;
            $materialLossRemaining -= $actualLost;
        }

        foreach ($itemLogs->sortByDesc('id') as $log) {
            if ($itemLossRemaining <= 0) {
                break;
            }

            $characterItem = $log->characterItem;
            $item = $characterItem?->item;
            if (!$characterItem || (int) $characterItem->character_id !== (int) $character->id) {
                continue;
            }

            $lostItems[] = [
                'character_item_id' => (int) $characterItem->id,
                'name' => $characterItem->displayName(),
                'rank' => $item?->weapon_rank ?? $item?->armor_rank ?? $item?->accessory_rank ?? $item?->rarity,
            ];
            $characterItem->delete();
            $itemLostTotal++;
            $itemLossRemaining--;
        }

        ExplorationLootLog::whereIn('id', $logs->pluck('id'))->update(['penalized' => true]);

        return [
            'total_lost' => $materialLostTotal + $itemLostTotal,
            'loss_percent' => $lossPercent,
            'material_lost' => $materialLostTotal,
            'item_lost' => $itemLostTotal,
            'materials' => $lostMaterials,
            'items' => $lostItems,
        ];
    }

    public function reset(Character $character, ?int $areaId = null): void
    {
        $state = CharacterExplorationState::firstOrCreate(
            ['character_id' => $character->id],
            [
                'area_id' => $areaId,
                'started_at' => now(),
            ]
        );

        $keepInsurance = $areaId !== null
            && $state->area_id === null
            && (bool) ($state->rescue_insurance_enabled ?? false);

        $state->forceFill([
            'area_id' => $areaId,
            'exploration_point' => 0,
            'chain_count' => 0,
            'danger_rate' => 0,
            'last_treasure_band' => 0,
            'treasure_found_count' => 0,
            'secret_realm_found_count' => 0,
            'dungeon_lord_encountered' => false,
            'valmon_material_found' => false,
            'valmon_heal_used' => false,
            'rescue_insurance_enabled' => $keepInsurance,
            'started_at' => $areaId ? now() : null,
        ])->save();

        app(ExplorationItemService::class)->reset($character);
    }

    public function resetDepthProgress(Character $character, int $areaId): void
    {
        $state = $this->getOrStart($character, $areaId);

        $state->forceFill([
            'exploration_point' => 0,
            'chain_count' => 0,
            'danger_rate' => 0,
            'last_treasure_band' => 0,
            'treasure_found_count' => 0,
            'secret_realm_found_count' => 0,
            'dungeon_lord_encountered' => false,
            'valmon_material_found' => false,
            'valmon_heal_used' => false,
        ])->save();
    }

    public function startAtDepth(Character $character, int $areaId, string $depthKey): ?CharacterExplorationState
    {
        $tier = app(ExplorationDepthService::class)->tierByKey($depthKey);
        if (!$tier || !in_array($depthKey, ['deepest', 'otherworld'], true)) {
            return null;
        }

        $state = CharacterExplorationState::firstOrCreate(
            ['character_id' => $character->id],
            ['area_id' => $areaId]
        );

        $state->forceFill([
            'area_id' => $areaId,
            'exploration_point' => (int) $tier['min_point'],
            'chain_count' => 0,
            'danger_rate' => 0,
            'last_treasure_band' => $this->treasureBand((int) $tier['min_point']),
            'treasure_found_count' => 0,
            'secret_realm_found_count' => 0,
            'dungeon_lord_encountered' => false,
            'valmon_material_found' => false,
            'valmon_heal_used' => false,
            'rescue_insurance_enabled' => false,
            'started_at' => now(),
        ])->save();

        app(ExplorationItemService::class)->reset($character);

        return $state->fresh();
    }

    public function resetDangerForDepthEntrance(Character $character, int $areaId): void
    {
        $state = $this->currentFor($character);
        if (!$state || (int) $state->area_id !== $areaId) {
            return;
        }

        $state->forceFill(['danger_rate' => 0])->save();
    }

    public function pointForEnemy(Enemy $enemy): int
    {
        if ($enemy->is_boss) {
            return 0;
        }

        $role = (string) ($enemy->role ?? '');
        $typeName = (string) ($enemy->type_name ?? '');
        $name = (string) ($enemy->name ?? '');
        $text = $role . ' ' . $typeName . ' ' . $name;

        if (str_contains($text, '黄金') || str_contains($text, 'ダンジョン主')) {
            return 0;
        }

        if (str_contains($text, 'レア')) {
            return rand(15, 30);
        }

        if (str_contains($text, '強') || str_contains($text, '精鋭')) {
            return rand(8, 18);
        }

        return rand(5, 15);
    }

    public function nextMilestone(int $point): ?array
    {
        foreach ($this->milestones() as $milestone) {
            if ($point < $milestone['point']) {
                $milestone['remaining'] = $milestone['point'] - $point;
                return $milestone;
            }
        }

        return null;
    }

    public function summaryForArea(Character $character, Area $area): array
    {
        $state = $this->currentFor($character);
        $isCurrentArea = $state && (int) $state->area_id === (int) $area->id;
        $point = $isCurrentArea ? (int) $state->exploration_point : 0;
        $chain = $isCurrentArea ? (int) $state->chain_count : 0;
        $danger = $isCurrentArea ? (int) ($state->danger_rate ?? 0) : 0;
        $depth = app(ExplorationDepthService::class)->summary($character, $area, $point, $danger);

        return [
            'exploration_point' => $point,
            'chain_count' => $chain,
            'danger_rate' => $danger,
            'danger_label' => $this->dangerLabel($danger),
            'depth' => $depth,
            'next_milestone' => $this->nextMilestone($point),
        ];
    }

    public function dangerLabel(int $dangerRate): string
    {
        return match (true) {
            $dangerRate >= 100 => '魔境',
            $dangerRate >= 75 => '深層',
            $dangerRate >= 50 => '危険',
            $dangerRate >= 25 => '警戒',
            default => '安定',
        };
    }

    public function treasureBand(int $explorationPoint): int
    {
        if ($explorationPoint < 100) {
            return 0;
        }

        return intdiv($explorationPoint - 100, 200) + 1;
    }

    public function canRollTreasure(CharacterExplorationState $state, int $explorationPoint): bool
    {
        return $this->treasureRate($state, $explorationPoint) > 0;
    }

    public function treasureRate(CharacterExplorationState $state, int $explorationPoint): float
    {
        if ($explorationPoint < 100) {
            return 0.0;
        }

        $baseRate = min(15.0, 5.0 + max(0, $this->treasureBand($explorationPoint) - 1));
        $foundCount = max(0, (int) ($state->treasure_found_count ?? 0));
        $divisor = 2 ** min($foundCount, 10);

        return max(0.01, $baseRate / $divisor);
    }

    public function markTreasureFound(Character $character, int $areaId): void
    {
        $state = $this->getOrStart($character, $areaId);
        $band = $this->treasureBand((int) $state->exploration_point);

        $state->forceFill([
            'last_treasure_band' => max($band, (int) ($state->last_treasure_band ?? 0)),
            'treasure_found_count' => (int) ($state->treasure_found_count ?? 0) + 1,
        ])->save();
    }

    public function secretRealmRate(CharacterExplorationState $state, float $baseRate): float
    {
        if ($baseRate <= 0) {
            return 0.0;
        }

        $foundCount = max(0, (int) ($state->secret_realm_found_count ?? 0));
        $divisor = 10 ** min($foundCount, 10);

        return $baseRate / $divisor;
    }

    public function markSecretRealmFound(Character $character, int $areaId): void
    {
        $state = $this->getOrStart($character, $areaId);

        $state->forceFill([
            'secret_realm_found_count' => (int) ($state->secret_realm_found_count ?? 0) + 1,
        ])->save();
    }

    public function resetTreasureFoundCount(Character $character, int $areaId): void
    {
        $state = $this->getOrStart($character, $areaId);

        $state->forceFill(['treasure_found_count' => 0])->save();
    }

    private function rollDangerIncrease(Character $character, Enemy $enemy, int $beforeDanger): array
    {
        $chance = 10;
        $amount = 5;
        $rolled = rand(1, 100);
        $increased = $rolled <= $chance;
        $afterDanger = $increased ? $beforeDanger + $amount : $beforeDanger;

        return [
            'before' => $beforeDanger,
            'after' => $afterDanger,
            'increased' => $increased,
            'increase' => $increased ? $afterDanger - $beforeDanger : 0,
            'chance' => $chance,
            'amount' => $amount,
            'label' => $this->dangerLabel($afterDanger),
        ];
    }

    private function rollDangerIncreaseRepeated(Character $character, Enemy $enemy, int $beforeDanger, int $times): array
    {
        $times = max(1, $times);
        $afterDanger = $beforeDanger;
        $totalIncrease = 0;
        $increased = false;
        $chance = 10;
        $amount = 5;

        for ($i = 0; $i < $times; $i++) {
            $roll = $this->rollDangerIncrease($character, $enemy, $afterDanger);
            $afterDanger = (int) $roll['after'];
            $totalIncrease += (int) $roll['increase'];
            $increased = $increased || (bool) $roll['increased'];
            $chance = (int) $roll['chance'];
            $amount = (int) $roll['amount'];
        }

        return [
            'before' => $beforeDanger,
            'after' => $afterDanger,
            'increased' => $increased,
            'increase' => $totalIncrease,
            'chance' => $chance,
            'amount' => $amount,
            'label' => $this->dangerLabel($afterDanger),
        ];
    }

    private function lossCount(int $total, int $lossPercent): int
    {
        if ($total <= 0 || $lossPercent <= 0) {
            return 0;
        }

        return (int) floor($total * min(100, $lossPercent) / 100);
    }

    private function milestones(): array
    {
        return [
            ['point' => 100, 'point_label' => '探索度100以上', 'name' => '宝箱抽選対象', 'message' => '輝く宝箱の出現抽選対象になりました。'],
            ['point' => 200, 'name' => '黄金ゴブリン', 'message' => '黄金ゴブリンが出現する可能性があります。'],
            ['point' => 300, 'name' => 'ダンジョン主', 'message' => 'ダンジョン主が出現する可能性があります。'],
            ['point' => 500, 'name' => '秘境への入口', 'message' => '秘境への入口を発見できるかもしれません。'],
        ];
    }

    private function crossedMilestones(int $before, int $after): array
    {
        return array_values(array_filter(
            $this->milestones(),
            fn (array $milestone) => $before < $milestone['point'] && $after >= $milestone['point']
        ));
    }
}
