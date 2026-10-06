<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use RuntimeException;
use App\Support\MaterialInventoryRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StorageCapacityService
{
    public const CITY_CLEAR_MATERIAL_STORAGE_BONUS = 200;
    public const CITY_CLEAR_EQUIPMENT_STORAGE_BONUS = 100;

    private const CITY_FINAL_NORMAL_AREA_IDS = [7, 14, 21, 28, 35, 42, 49, 56, 63, 70];

    public function summary(Character $character): array
    {
        $materialTotal = CharacterMaterial::query()
            ->where('character_id', $character->id)
            ->where('quantity', '>', 0)
            ->whereHas('material')
            ->with('material')
            ->get()
            ->reject(fn (CharacterMaterial $row) => $this->isKeyMaterial($row->material))
            ->sum('quantity');

        $equipmentTotal = CharacterItem::query()
            ->where('character_id', $character->id)
            ->whereHas('item', fn ($query) => $query->whereIn('type', ['weapon', 'armor', 'accessory']))
            ->with('item')
            ->get()
            ->reject(fn (CharacterItem $row) => $this->isKeyItem($row))
            ->count();

        return $this->summaryFromOwnedTotals($character, (int) $materialTotal, (int) $equipmentTotal);
    }

    /** Reuse collections already read for the warehouse; execution calls summary() for current assets. */
    public function summaryFromOwnedTotals(Character $character, int $materialTotal, int $equipmentTotal): array
    {
        $additional = $this->namelessOwnedTotals($character);
        $materialTotal += $additional['relic_total'];
        $equipmentTotal += $additional['nameless_equipment_total'];
        $cityClearBonusCount = $this->cityClearStorageBonusCount($character);
        $materialLimit = $this->materialLimitForBonusCount($character, $cityClearBonusCount);
        $equipmentLimit = $this->equipmentLimitForBonusCount($character, $cityClearBonusCount);

        return $additional + [
            'material_total' => $materialTotal,
            'material_limit' => $materialLimit,
            'equipment_total' => $equipmentTotal,
            'equipment_limit' => $equipmentLimit,
            'material_free' => max(0, $materialLimit - $materialTotal),
            'equipment_free' => max(0, $equipmentLimit - $equipmentTotal),
            'material_full' => $materialLimit > 0 && $materialTotal >= $materialLimit,
            'equipment_full' => $equipmentLimit > 0 && $equipmentTotal >= $equipmentLimit,
        ];
    }

    /** Count stored assets even when OFF; absent prototype tables/owner columns must not break ordinary play. */
    private function namelessOwnedTotals(Character $character): array
    {
        $totals = [];
        foreach (['player_nameless_equipments' => 'nameless_equipment_total', 'player_relics' => 'relic_total'] as $table => $key) {
            $totals[$key] = Schema::hasColumn($table, 'character_id')
                ? DB::table($table)->where('character_id', $character->id)->count() : 0;
        }

        return $totals;
    }

    public function isFull(Character $character): bool
    {
        $summary = $this->summary($character);

        return $summary['material_full'] || $summary['equipment_full'];
    }

    public function assertCanReceiveEquipment(Character $character, int $count = 1): void
    {
        $summary = $this->summary($character);
        if ($summary['equipment_limit'] > 0 && $summary['equipment_total'] + $count > $summary['equipment_limit']) {
            throw new RuntimeException('装備所持枠が不足しています。');
        }
    }

    public function fullMessageHtml(Character $character, ?array $summary = null): string
    {
        $summary ??= $this->summary($character);
        $lines = [];

        if ($summary['material_full']) {
            $lines[] = '素材倉庫: ' . number_format($summary['material_total']) . ' / ' . number_format($summary['material_limit']);
        }

        if ($summary['equipment_full']) {
            $lines[] = '装備倉庫: ' . number_format($summary['equipment_total']) . ' / ' . number_format($summary['equipment_limit']);
        }

        $details = $lines ? '<br><span class="text-xs">' . e(implode('　', $lines)) . '</span>' : '';
        $inventoryUrl = route('inventory.index');
        $supportUrl = route('kiseki.support');

        $workshopLink = '';
        if ((($summary['relic_total'] ?? 0) > 0 || ($summary['nameless_equipment_total'] ?? 0) > 0)
            && app(NamelessWorkshopService::class)->ready()) {
            $workshopLink = ' 名もなき武具・遺物の整理は<a href="'.e(route('nameless-workshop.index')).'" class="underline underline-offset-2 font-extrabold">鍛冶屋</a>で行えます。';
        }

        return '倉庫がいっぱいです。探索する前に'
            . '<a href="' . e($inventoryUrl) . '" class="underline underline-offset-2 font-extrabold">倉庫の整理</a>'
            . 'をしてください。倉庫の拡張は'
            . '<a href="' . e($supportUrl) . '" class="underline underline-offset-2 font-extrabold">こちら</a>'
            . 'で行えます。'
            . $details
            . $workshopLink;
    }

    public function materialLimit(Character $character): int
    {
        return $this->materialLimitForBonusCount($character, $this->cityClearStorageBonusCount($character));
    }

    public function equipmentLimit(Character $character): int
    {
        return $this->equipmentLimitForBonusCount($character, $this->cityClearStorageBonusCount($character));
    }

    /**
     * @return array<string,int>
     */
    public function nextCityClearStorageReward(Character $character): array
    {
        $cityClearBonusCount = $this->cityClearStorageBonusCount($character);
        $materialBefore = $this->materialLimitForBonusCount($character, $cityClearBonusCount);
        $equipmentBefore = $this->equipmentLimitForBonusCount($character, $cityClearBonusCount);

        return [
            'material_bonus' => self::CITY_CLEAR_MATERIAL_STORAGE_BONUS,
            'equipment_bonus' => self::CITY_CLEAR_EQUIPMENT_STORAGE_BONUS,
            'material_before' => $materialBefore,
            'material_after' => $materialBefore + self::CITY_CLEAR_MATERIAL_STORAGE_BONUS,
            'equipment_before' => $equipmentBefore,
            'equipment_after' => $equipmentBefore + self::CITY_CLEAR_EQUIPMENT_STORAGE_BONUS,
        ];
    }

    public function cityClearStorageBonusCount(Character $character): int
    {
        return $character->titles()
            ->join('titles', 'character_titles.title_id', '=', 'titles.id')
            ->where('titles.unlock_type', 'dungeon_boss_clear')
            ->where('titles.target_type', 'dungeon')
            ->whereIn('titles.target_id', array_map('strval', self::CITY_FINAL_NORMAL_AREA_IDS))
            ->distinct('titles.id')
            ->count('titles.id');
    }

    private function materialLimitForBonusCount(Character $character, int $cityClearBonusCount): int
    {
        $baseLimit = max(500, (int) ($character->material_storage_limit ?? 500));

        return $baseLimit + ($cityClearBonusCount * self::CITY_CLEAR_MATERIAL_STORAGE_BONUS);
    }

    private function equipmentLimitForBonusCount(Character $character, int $cityClearBonusCount): int
    {
        $baseLimit = max(300, (int) ($character->equipment_storage_limit ?? 300));

        return $baseLimit + ($cityClearBonusCount * self::CITY_CLEAR_EQUIPMENT_STORAGE_BONUS);
    }

    private function isKeyMaterial(?object $material): bool
    {
        return MaterialInventoryRules::isKeyMaterial($material);
    }

    private function isKeyItem(CharacterItem $characterItem): bool
    {
        $item = $characterItem->item;
        if (!$item) {
            return false;
        }

        $name = (string) ($item->name ?? '');
        $subType = (string) ($item->sub_type ?? '');

        return in_array($subType, ['刻印', '王印', '神印'], true)
            || str_ends_with($name, 'の刻印')
            || str_ends_with($name, 'の王印')
            || str_ends_with($name, 'の神印');
    }
}
