<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\MapExplorationBatch;
use App\Models\MapExplorationResult;
use App\Models\TownMapRegistration;
use Illuminate\Support\Facades\DB;

class MapExplorationDefeatService
{
    private const MATERIAL_LOSS_PERCENT = 50;

    /**
     * 通常探索と同じ敗北ロストを、現在の地図入場中に得た戦利品へ適用する。
     *
     * @return array{material_penalty: array<string, mixed>, gold_loss: array<string, mixed>, valmon_egg_lost: array<int, mixed>}
     */
    public function apply(Character $character, MapExplorationBatch $batch): array
    {
        return DB::transaction(function () use ($character, $batch): array {
            $lockedCharacter = Character::query()->lockForUpdate()->findOrFail($character->id);
            $entryStartedAt = app(MapExplorationItemService::class)->entryStartedAt(
                $lockedCharacter,
                (int) $batch->registration_id,
            ) ?? $batch->created_at ?? now();

            $results = MapExplorationResult::query()
                ->where('character_id', $lockedCharacter->id)
                ->where('registration_id', $batch->registration_id)
                ->where('created_at', '>=', $entryStartedAt)
                ->where('battle_result', 'victory')
                ->orderBy('id')
                ->get();

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
        $itemLossRemaining = $this->lossCount(count($itemIds));

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
}
