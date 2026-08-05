<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EquipmentDiscoveryService
{
    public const TABLE = 'character_equipment_discoveries';

    public function record(int $characterId, int $itemId): void
    {
        if (!Schema::hasTable(self::TABLE) || !$this->isBookEquipment($itemId)) {
            return;
        }

        DB::table(self::TABLE)->insertOrIgnore([
            'character_id' => $characterId,
            'item_id' => $itemId,
            'discovered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function syncFor(Character $character): array
    {
        if (!Schema::hasTable(self::TABLE)) {
            return [];
        }

        $itemIds = DB::table('character_items')
            ->join('items', 'items.id', '=', 'character_items.item_id')
            ->where('character_items.character_id', $character->id)
            ->whereIn('items.type', ['weapon', 'armor'])
            ->pluck('character_items.item_id')
            ->map(fn ($itemId): int => (int) $itemId)
            ->all();

        if (Schema::hasTable('equipment_evolution_logs')) {
            $evolvedItemIds = DB::table('equipment_evolution_logs')
                ->where('character_id', $character->id)
                ->get(['before_equipment_id', 'after_equipment_id'])
                ->flatMap(fn (object $row): array => [
                    (int) $row->before_equipment_id,
                    (int) $row->after_equipment_id,
                ])
                ->all();
            $itemIds = array_merge($itemIds, $evolvedItemIds);
        }

        $this->recordMany((int) $character->id, $itemIds);

        return DB::table(self::TABLE)
            ->where('character_id', $character->id)
            ->pluck('item_id')
            ->map(fn ($itemId): int => (int) $itemId)
            ->all();
    }

    private function recordMany(int $characterId, array $itemIds): void
    {
        $itemIds = Item::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $itemIds))))
            ->whereIn('type', ['weapon', 'armor'])
            ->pluck('id')
            ->map(fn ($itemId): int => (int) $itemId)
            ->all();

        if ($itemIds === []) {
            return;
        }

        $now = now();
        DB::table(self::TABLE)->insertOrIgnore(array_map(
            fn (int $itemId): array => [
                'character_id' => $characterId,
                'item_id' => $itemId,
                'discovered_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $itemIds
        ));
    }

    private function isBookEquipment(int $itemId): bool
    {
        return Item::query()
            ->whereKey($itemId)
            ->whereIn('type', ['weapon', 'armor'])
            ->exists();
    }
}
