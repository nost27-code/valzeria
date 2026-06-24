<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $items = [
            [
                'name' => '薬草',
                'description' => '探索中にHPを30%回復する基本の薬草。',
                'price' => 80,
                'sort_order' => 10,
            ],
            [
                'name' => '回復薬',
                'description' => '探索中にHPを60%回復する薬。',
                'price' => 220,
                'sort_order' => 20,
            ],
            [
                'name' => '魔力水',
                'description' => '探索中にMPを30%回復する魔力を帯びた水。',
                'price' => 180,
                'sort_order' => 30,
            ],
        ];

        foreach ($items as $item) {
            DB::table('items')->updateOrInsert(
                ['name' => $item['name'], 'type' => 'consumable'],
                [
                    'description' => $item['description'],
                    'rarity' => 'normal',
                    'price' => $item['price'],
                    'hp_bonus' => 0,
                    'mp_bonus' => 0,
                    'str_bonus' => 0,
                    'def_bonus' => 0,
                    'agi_bonus' => 0,
                    'mag_bonus' => 0,
                    'spr_bonus' => 0,
                    'luk_bonus' => 0,
                    'required_level' => 1,
                    'is_shop_item' => true,
                    'is_active' => true,
                    'sort_order' => $item['sort_order'],
                    'sub_type' => '回復',
                    'element' => null,
                    'unlock_city_id' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('items')
            ->where('type', 'consumable')
            ->whereIn('name', ['薬草', '回復薬', '魔力水'])
            ->delete();
    }
};
