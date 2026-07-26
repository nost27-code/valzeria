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
            ['name' => '薬屋のお守り', 'description' => '50戦有効。5戦ごとの戦闘後、最大HPの10%を回復する。', 'sort_order' => 91],
            ['name' => '守りの香', 'description' => '50戦有効。敵から受ける直接ダメージを8%軽減する。', 'sort_order' => 92],
            ['name' => '冒険者の救急包', 'description' => '50戦有効。火傷・毒・出血への備えになる。', 'sort_order' => 93],
            ['name' => '薬屋の特製漢方', 'description' => '50戦有効。瀕死時に最大HPの20%を回復する。', 'sort_order' => 94],
            ['name' => '誘魔香〈獣〉', 'description' => '50戦有効。通常探索で獣系の敵の出現しやすさが3倍になる。', 'sort_order' => 95],
            ['name' => '誘魔香〈不死〉', 'description' => '50戦有効。通常探索で不死系の敵の出現しやすさが3倍になる。', 'sort_order' => 96],
            ['name' => '誘魔香〈竜〉', 'description' => '50戦有効。通常探索で竜系の敵の出現しやすさが3倍になる。', 'sort_order' => 97],
            ['name' => '誘魔香〈悪魔〉', 'description' => '50戦有効。通常探索で悪魔系の敵の出現しやすさが3倍になる。', 'sort_order' => 98],
            ['name' => '誘魔香〈水棲〉', 'description' => '50戦有効。通常探索で水棲系の敵の出現しやすさが3倍になる。', 'sort_order' => 99],
            ['name' => '誘魔香〈飛行〉', 'description' => '50戦有効。通常探索で飛行系の敵の出現しやすさが3倍になる。', 'sort_order' => 100],
            ['name' => '誘魔香〈虫〉', 'description' => '50戦有効。通常探索で虫系の敵の出現しやすさが3倍になる。', 'sort_order' => 101],
            ['name' => '誘魔香〈機械〉', 'description' => '50戦有効。通常探索で機械系の敵の出現しやすさが3倍になる。', 'sort_order' => 102],
            ['name' => '誘魔香〈スライム〉', 'description' => '50戦有効。通常探索でスライム系の敵の出現しやすさが3倍になる。', 'sort_order' => 103],
            ['name' => '誘魔香〈人型〉', 'description' => '50戦有効。通常探索で人型系の敵の出現しやすさが3倍になる。', 'sort_order' => 104],
            ['name' => '誘魔香〈魔法型〉', 'description' => '50戦有効。通常探索で魔法型系の敵の出現しやすさが3倍になる。', 'sort_order' => 105],
            ['name' => '誘魔香〈精霊〉', 'description' => '50戦有効。通常探索で精霊系の敵の出現しやすさが3倍になる。', 'sort_order' => 106],
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
