<?php

namespace Database\Seeders;

use App\Models\Enemy;
use App\Models\EnemyDrop;
use App\Models\Item;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DropEquipmentAdditionsSeeder extends Seeder
{
    private const DATA_PATH = 'database/data/drop_equipment_additions.json';

    public function run(): void
    {
        $path = base_path(self::DATA_PATH);
        if (!file_exists($path)) {
            $this->command?->warn(self::DATA_PATH . ' が見つからないため、敵別装備ドロップ追加をスキップしました。');
            return;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            $this->command?->warn(self::DATA_PATH . ' のJSONを読み込めませんでした。');
            return;
        }

        $items = $data['items'] ?? [];
        $drops = $data['drops'] ?? [];
        $itemMap = [];
        $itemCount = 0;
        $dropCount = 0;

        DB::transaction(function () use ($items, $drops, &$itemMap, &$itemCount, &$dropCount) {
            foreach ($items as $row) {
                $item = $this->upsertItem($row);
                $sourceId = (int) ($row['id'] ?? 0);
                if ($sourceId > 0) {
                    $itemMap[$sourceId] = $item->id;
                }
                $itemCount++;
            }

            foreach ($drops as $row) {
                $enemy = $this->findEnemy($row);
                if (!$enemy) {
                    $this->command?->warn('敵別装備ドロップの敵が見つかりません: ' . ($row['ダンジョン'] ?? '') . ' / ' . ($row['敵の名前'] ?? ''));
                    continue;
                }

                $sourceItemId = (int) ($row['装備ID'] ?? 0);
                $itemId = $itemMap[$sourceItemId] ?? null;
                $item = $itemId ? Item::find($itemId) : Item::where('name', (string) ($row['ドロップアイテム'] ?? ''))->first();
                if (!$item) {
                    $this->command?->warn('敵別装備ドロップの装備が見つかりません: ' . ($row['ドロップアイテム'] ?? ''));
                    continue;
                }

                EnemyDrop::updateOrCreate(
                    [
                        'enemy_id' => $enemy->id,
                        'item_id' => $item->id,
                    ],
                    [
                        'drop_rate' => $this->normalizeRate($row['ドロップ率'] ?? 0),
                        'min_character_level' => 1,
                        'max_character_level' => null,
                        'is_active' => true,
                    ]
                );

                $dropCount++;
            }
        });

        $this->command?->info("敵別ドロップ装備を {$itemCount} 件、敵別装備ドロップを {$dropCount} 件更新しました。");
    }

    private function upsertItem(array $row): Item
    {
        $payload = $this->itemPayload($row);
        $externalId = (string) ($payload['external_item_id'] ?? '');

        $item = $externalId !== ''
            ? Item::where('external_item_id', $externalId)->first()
            : null;

        if (!$item) {
            $item = Item::where('type', (string) ($payload['type'] ?? ''))
                ->where('name', (string) ($payload['name'] ?? ''))
                ->first();
        }

        if ($item) {
            $item->fill($payload);
            $item->save();
            return $item;
        }

        return Item::create($payload);
    }

    private function itemPayload(array $row): array
    {
        $keys = [
            'name', 'type', 'description', 'rarity', 'price', 'sell_price',
            'hp_bonus', 'mp_bonus', 'str_bonus', 'def_bonus', 'agi_bonus', 'mag_bonus', 'spr_bonus', 'luk_bonus',
            'required_level', 'is_shop_item', 'is_active', 'sort_order', 'unlock_city_id',
            'sub_type', 'element',
            'weapon_category', 'weapon_hand_type', 'weapon_role',
            'external_item_id', 'weapon_family_id', 'weapon_family_name',
            'weapon_rank', 'weapon_rank_sort', 'weapon_rank_multiplier',
            'evolution_stage', 'next_item_external_id',
            'is_evolution_enabled', 'is_drop_enabled', 'is_supply_enabled', 'max_enhance',
            'armor_category', 'armor_weight', 'armor_role',
            'armor_family_id', 'armor_family_name', 'armor_category_id', 'armor_category_name',
            'armor_rank', 'armor_rank_sort', 'armor_rank_multiplier',
            'evolution_group_id', 'next_armor_external_id',
            'accessory_family_id', 'accessory_family_name', 'accessory_category_id', 'accessory_category_name',
            'accessory_rank', 'accessory_rank_sort', 'accessory_rank_multiplier', 'next_accessory_external_id',
        ];

        $payload = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                $payload[$key] = $row[$key];
            }
        }

        foreach (['is_shop_item', 'is_active', 'is_evolution_enabled', 'is_drop_enabled', 'is_supply_enabled'] as $key) {
            $payload[$key] = (bool) ($payload[$key] ?? false);
        }

        foreach ([
            'price', 'sell_price', 'hp_bonus', 'mp_bonus', 'str_bonus', 'def_bonus', 'agi_bonus', 'mag_bonus',
            'spr_bonus', 'luk_bonus', 'required_level', 'sort_order', 'unlock_city_id', 'weapon_rank_sort',
            'evolution_stage', 'max_enhance', 'armor_rank_sort', 'accessory_rank_sort',
        ] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null) {
                $payload[$key] = (int) $payload[$key];
            }
        }

        foreach (['weapon_rank_multiplier', 'armor_rank_multiplier', 'accessory_rank_multiplier'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null) {
                $payload[$key] = (float) $payload[$key];
            }
        }

        return $payload;
    }

    private function findEnemy(array $row): ?Enemy
    {
        $enemyName = trim((string) ($row['敵の名前'] ?? ''));
        $areaName = trim((string) ($row['ダンジョン'] ?? ''));

        if ($enemyName === '') {
            return null;
        }

        if ($areaName !== '') {
            $enemy = Enemy::where('name', $enemyName)
                ->whereHas('area', fn ($query) => $query->where('name', $areaName))
                ->first();
            if ($enemy) {
                return $enemy;
            }
        }

        return Enemy::where('name', $enemyName)->first();
    }

    private function normalizeRate(mixed $rate): float
    {
        $value = (float) $rate;
        if ($value > 0 && $value <= 1) {
            $value *= 100;
        }

        return round($value, 2);
    }
}
