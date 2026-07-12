<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExplorationSupportMasterSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach ([
            ['name' => '薬屋のお守り', 'description' => '30戦有効。5戦ごとの戦闘後、最大HPの10%を回復する。', 'sort_order' => 91],
            ['name' => '守りの香', 'description' => '30戦有効。敵から受ける直接ダメージを8%軽減する。', 'sort_order' => 92],
            ['name' => '冒険者の救急包', 'description' => '30戦有効。火傷・毒・出血への備えになる。', 'sort_order' => 93],
            ['name' => '薬屋の特製漢方', 'description' => '30戦有効。瀕死時に最大HPの20%を回復する。', 'sort_order' => 94],
        ] as $item) {
            DB::table('items')->updateOrInsert(['name' => $item['name'], 'type' => 'consumable'], array_merge($item, [
                'rarity' => 'R', 'price' => 0, 'sell_price' => 0, 'hp_bonus' => 0, 'mp_bonus' => 0,
                'str_bonus' => 0, 'def_bonus' => 0, 'agi_bonus' => 0, 'mag_bonus' => 0, 'spr_bonus' => 0, 'luk_bonus' => 0,
                'required_level' => 1, 'is_shop_item' => false, 'is_active' => true, 'sub_type' => '探索補助品', 'element' => null,
                'updated_at' => $now, 'created_at' => $now,
            ]));
        }
    }
}
