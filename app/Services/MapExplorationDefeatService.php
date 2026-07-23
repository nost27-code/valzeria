<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\MapExplorationBatch;
use App\Models\MapExplorationResult;
use App\Models\Material;
use App\Models\TownMapRegistration;
use Illuminate\Support\Facades\DB;

class MapExplorationDefeatService
{
    private const MATERIAL_LOSS_PERCENT = 50;

    /**
     * 現在の地図入場中に得た戦利品を、通常探索の連戦表示と同じ形式で返す。
     */
    public function currentLootSummary(Character $character, int $registrationId): array
    {
        $results = $this->entryResults($character, $registrationId);
        if ($results->isEmpty()) {
            return $this->emptyLootSummary();
        }

        $materialDrops = [];
        $equipmentIds = [];
        foreach ($results as $result) {
            foreach ((array) data_get($result->drops_json, 'materials', []) as $drop) {
                $materialId = (int) ($drop['material_id'] ?? 0);
                if ($materialId <= 0) {
                    continue;
                }

                if (!isset($materialDrops[$materialId])) {
                    $materialDrops[$materialId] = $drop;
                    $materialDrops[$materialId]['quantity'] = 0;
                }
                $materialDrops[$materialId]['quantity'] += max(1, (int) ($drop['quantity'] ?? 1));
            }

            foreach ((array) data_get($result->drops_json, 'equipment', []) as $drop) {
                $characterItemId = (int) ($drop['character_item_id'] ?? 0);
                if ($characterItemId > 0) {
                    $equipmentIds[] = $characterItemId;
                }
            }
        }

        $materialsById = Material::query()->whereIn('id', array_keys($materialDrops))->get()->keyBy('id');
        $materials = collect($materialDrops)
            ->map(function (array $drop, int $materialId) use ($materialsById): array {
                $material = $materialsById->get($materialId);
                $rarity = strtoupper((string) ($material?->rarity ?? $drop['rarity'] ?? ''));
                $name = $material?->displayName() ?? (string) ($drop['name'] ?? '不明な素材');

                return [
                    'name' => $name,
                    'rarity' => $rarity,
                    'is_sr' => $rarity === 'SR',
                    'is_sell_treasure' => (bool) $material?->isSellTreasure(),
                    'icon_image' => $material?->iconImagePath() ?? ($drop['icon_image'] ?? Material::iconImagePathFor($drop['material_code'] ?? null, $name)),
                    'material_code' => $material?->material_code ?? ($drop['material_code'] ?? null),
                    'quantity' => (int) ($drop['quantity'] ?? 0),
                ];
            })
            ->sortBy('name')
            ->values();

        $itemIds = array_values(array_unique($equipmentIds));
        $characterItems = CharacterItem::query()
            ->with('item')
            ->where('character_id', $character->id)
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');
        $items = collect($itemIds)
            ->map(function (int $itemId) use ($characterItems): array {
                $characterItem = $characterItems->get($itemId);
                $item = $characterItem?->item;

                return [
                    'name' => $characterItem?->displayName() ?? '不明な装備',
                    'rank' => $item?->weapon_rank ?? $item?->armor_rank ?? $item?->accessory_rank ?? $item?->rarity,
                    'can_lose' => $characterItem && ! $characterItem->is_equipped && ! $characterItem->is_locked,
                ];
            })
            ->values();

        $materialTotal = (int) $materials->sum('quantity');
        $itemTotal = $items->count();
        $riskMaterialTotal = $this->lossCount($materialTotal);
        $riskItemTotal = $this->lossCount($items->where('can_lose', true)->count());

        return [
            'materials' => $materials->all(),
            'items' => $items->all(),
            'material_total' => $materialTotal,
            'item_total' => $itemTotal,
            'risk_material_total' => $riskMaterialTotal,
            'risk_item_total' => $riskItemTotal,
            'risk_total' => $riskMaterialTotal + $riskItemTotal,
        ];
    }

    /**
     * 通常探索と同じ敗北ロストを、現在の地図入場中に得た戦利品へ適用する。
     *
     * @return array{material_penalty: array<string, mixed>, gold_loss: array<string, mixed>, valmon_egg_lost: array<int, mixed>}
     */
    public function apply(Character $character, MapExplorationBatch $batch): array
    {
        return DB::transaction(function () use ($character, $batch): array {
            $lockedCharacter = Character::query()->lockForUpdate()->findOrFail($character->id);
            $results = $this->entryResults($lockedCharacter, (int) $batch->registration_id, $batch->created_at ?? now());

            $materialDrops = [];
            $equipmentIds = [];
            foreach ($results as $result) {
                foreach ((array) data_get($result->drops_json, 'materials', []) as $drop) {
                    $materialId = (int) ($drop['material_id'] ?? 0);
                    if ($materialId <= 0) {
                        continue;
                    }

                    $materialDrops[$materialId] = ($materialDrops[$materialId] ?? 0) + max(1, (int) ($drop['quantity'] ?? 1));
                }

                foreach ((array) data_get($result->drops_json, 'equipment', []) as $drop) {
                    $characterItemId = (int) ($drop['character_item_id'] ?? 0);
                    if ($characterItemId > 0) {
                        $equipmentIds[] = $characterItemId;
                    }
                }
            }

            $materialPenalty = $this->loseMapLoot($lockedCharacter, $materialDrops, $equipmentIds);
            $goldLoss = app(GuildService::class)->calculateDefeatGoldLoss((int) $lockedCharacter->money);
            $goldLossAmount = (int) ($goldLoss['amount'] ?? 0);
            if ($goldLossAmount > 0) {
                app(GoldService::class)->spend(
                    $lockedCharacter,
                    $goldLossAmount,
                    'exploration_defeat_gold_loss',
                    '探索地図で敗北し、荷物を荒らされて失ったGold',
                    TownMapRegistration::class,
                    (int) $batch->registration_id,
                    ['registration_id' => (int) $batch->registration_id, 'map_id' => (int) $batch->map_id, 'rate' => (float) ($goldLoss['rate'] ?? 0)],
                );
            }

            return [
                'material_penalty' => $materialPenalty,
                'gold_loss' => $goldLoss,
                'valmon_egg_lost' => app(ValmonService::class)->loseActiveEggs($lockedCharacter),
            ];
        });
    }

    /** @param array<int, int> $materialDrops @param array<int, int> $equipmentIds */
    private function loseMapLoot(Character $character, array $materialDrops, array $equipmentIds): array
    {
        $lostMaterials = [];
        $lostItems = [];
        $materialLossRemaining = $this->lossCount(array_sum($materialDrops));
        $itemIds = array_values(array_unique($equipmentIds));
        $itemLossRemaining = $this->lossCount(
            CharacterItem::query()
                ->where('character_id', $character->id)
                ->whereIn('id', $itemIds)
                ->where('is_equipped', false)
                ->where('is_locked', false)
                ->count()
        );

        arsort($materialDrops);
        foreach ($materialDrops as $materialId => $droppedQuantity) {
            if ($materialLossRemaining <= 0) {
                break;
            }

            $owned = CharacterMaterial::query()
                ->with('material')
                ->where('character_id', $character->id)
                ->where('material_id', $materialId)
                ->lockForUpdate()
                ->first();
            if (! $owned || (int) $owned->quantity <= 0) {
                continue;
            }

            $lostQuantity = min($materialLossRemaining, (int) $droppedQuantity, (int) $owned->quantity);
            if ($lostQuantity <= 0) {
                continue;
            }

            $owned->decrement('quantity', $lostQuantity);
            $lostMaterials[] = [
                'material_id' => (int) $materialId,
                'name' => $owned->material?->displayName() ?? '不明な素材',
                'quantity' => $lostQuantity,
            ];
            $materialLossRemaining -= $lostQuantity;
        }

        if ($itemLossRemaining > 0 && $itemIds !== []) {
            $items = CharacterItem::query()
                ->with('item')
                ->where('character_id', $character->id)
                ->whereIn('id', $itemIds)
                ->where('is_equipped', false)
                ->where('is_locked', false)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();
            foreach ($items as $item) {
                if ($itemLossRemaining <= 0) {
                    break;
                }

                $lostItems[] = [
                    'character_item_id' => (int) $item->id,
                    'name' => $item->displayName(),
                    'rank' => $item->item?->weapon_rank ?? $item->item?->armor_rank ?? $item->item?->accessory_rank ?? $item->item?->rarity,
                ];
                $item->delete();
                $itemLossRemaining--;
            }
        }

        return [
            'total_lost' => count($lostItems) + array_sum(array_column($lostMaterials, 'quantity')),
            'loss_percent' => self::MATERIAL_LOSS_PERCENT,
            'material_lost' => array_sum(array_column($lostMaterials, 'quantity')),
            'item_lost' => count($lostItems),
            'materials' => $lostMaterials,
            'items' => $lostItems,
        ];
    }

    private function lossCount(int $total): int
    {
        return $total <= 0 ? 0 : (int) floor($total * self::MATERIAL_LOSS_PERCENT / 100);
    }

    private function entryResults(Character $character, int $registrationId, mixed $fallbackStartedAt = null)
    {
        $entryStartedAt = app(MapExplorationItemService::class)->entryStartedAt($character, $registrationId)
            ?? $fallbackStartedAt
            ?? now();

        return MapExplorationResult::query()
            ->where('character_id', $character->id)
            ->where('registration_id', $registrationId)
            ->where('created_at', '>=', $entryStartedAt)
            ->where('battle_result', 'victory')
            ->orderBy('id')
            ->get();
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
}
