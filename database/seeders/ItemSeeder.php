<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Item;

class ItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            // --- 武器 (weapon) ---
            ['name' => '木の剣', 'type' => 'weapon', 'price' => 10, 'required_level' => 1, 'str_bonus' => 5, 'description' => '初心者用の木製の剣', 'is_shop_item' => true],
            ['name' => '鉄の剣', 'type' => 'weapon', 'price' => 120, 'required_level' => 2, 'str_bonus' => 9, 'description' => '扱いやすい鉄製の剣', 'is_shop_item' => true],
            ['name' => '鋼の剣', 'type' => 'weapon', 'price' => 450, 'required_level' => 5, 'str_bonus' => 18, 'description' => '小鬼の森でも頼れる剣', 'is_shop_item' => true],
            ['name' => '魔術師の杖', 'type' => 'weapon', 'price' => 160, 'required_level' => 2, 'str_bonus' => 3, 'mag_bonus' => 8, 'description' => '魔力を高める杖', 'is_shop_item' => true],
            ['name' => '盗賊の短剣', 'type' => 'weapon', 'price' => 200, 'required_level' => 3, 'str_bonus' => 7, 'agi_bonus' => 5, 'luk_bonus' => 3, 'description' => '素早く扱える短剣', 'is_shop_item' => true],

            // --- 防具 (armor) ---
            ['name' => '布の服', 'type' => 'armor', 'price' => 10, 'required_level' => 1, 'def_bonus' => 4, 'description' => '最低限の防具', 'is_shop_item' => true],
            ['name' => '革の鎧', 'type' => 'armor', 'price' => 100, 'required_level' => 2, 'hp_bonus' => 7, 'def_bonus' => 7, 'description' => '軽くて動きやすい革鎧', 'is_shop_item' => true],
            ['name' => '鉄の鎧', 'type' => 'armor', 'price' => 400, 'required_level' => 5, 'hp_bonus' => 13, 'def_bonus' => 16, 'agi_bonus' => -1, 'description' => '防御力の高い鉄鎧', 'is_shop_item' => true],
            ['name' => '魔法のローブ', 'type' => 'armor', 'price' => 380, 'required_level' => 5, 'hp_bonus' => 7, 'def_bonus' => 8, 'mag_bonus' => 7, 'description' => '魔力を補うローブ', 'is_shop_item' => true],

            // --- 装飾品 (accessory) ---
            ['name' => '魔除けの護符', 'type' => 'accessory', 'price' => 10, 'required_level' => 1, 'luk_bonus' => 4, 'description' => '初心者用のお守り', 'is_shop_item' => true, 'unlock_city_id' => 1],
            ['name' => '旅人のお守り', 'type' => 'accessory', 'price' => 80, 'required_level' => 1, 'hp_bonus' => 7, 'luk_bonus' => 5, 'description' => '旅の安全を願うお守り', 'is_shop_item' => true, 'unlock_city_id' => 1],
            ['name' => '俊足の指輪', 'type' => 'accessory', 'price' => 220, 'required_level' => 3, 'agi_bonus' => 7, 'description' => '素早さを高める指輪', 'is_shop_item' => true, 'unlock_city_id' => 1],
            ['name' => '力の腕輪', 'type' => 'accessory', 'price' => 300, 'required_level' => 4, 'str_bonus' => 7, 'description' => 'STRを高める腕輪', 'is_shop_item' => true, 'unlock_city_id' => 1],
            ['name' => '魔力の首飾り', 'type' => 'accessory', 'price' => 300, 'required_level' => 4, 'mag_bonus' => 7, 'description' => 'MAGを高める首飾り', 'is_shop_item' => true, 'unlock_city_id' => 1],

            // --- ドロップ専用装備 ---
            // はじまりの草原
            ['name' => '草原の護符', 'type' => 'accessory', 'rarity' => 'normal', 'required_level' => 1, 'hp_bonus' => 5, 'luk_bonus' => 4, 'description' => '草原の魔力を少し帯びた護符', 'is_shop_item' => false],
            ['name' => 'うさぎの足飾り', 'type' => 'accessory', 'rarity' => 'rare', 'required_level' => 2, 'agi_bonus' => 6, 'luk_bonus' => 5, 'description' => '素早さと運を少し高める足飾り', 'is_shop_item' => false],
            ['name' => '古びた短剣', 'type' => 'weapon', 'rarity' => 'rare', 'required_level' => 3, 'str_bonus' => 8, 'agi_bonus' => 5, 'luk_bonus' => 3, 'description' => '草原で拾われた古い短剣', 'is_shop_item' => false],
            ['name' => '大スライムの護符', 'type' => 'accessory', 'rarity' => 'rare', 'required_level' => 4, 'hp_bonus' => 10, 'def_bonus' => 4, 'luk_bonus' => 5, 'description' => '草原の大スライムの核から作られた護符', 'is_shop_item' => false],
            // 小鬼の森
            ['name' => '小鬼の棍棒', 'type' => 'weapon', 'rarity' => 'normal', 'required_level' => 4, 'str_bonus' => 11, 'agi_bonus' => -1, 'description' => '小鬼が使っていた粗末な棍棒', 'is_shop_item' => false],
            ['name' => '森ネズミの首飾り', 'type' => 'accessory', 'rarity' => 'rare', 'required_level' => 5, 'agi_bonus' => 8, 'luk_bonus' => 4, 'description' => '森ネズミの素早さにあやかった首飾り', 'is_shop_item' => false],
            ['name' => 'ゴブリンソード', 'type' => 'weapon', 'rarity' => 'rare', 'required_level' => 6, 'str_bonus' => 16, 'agi_bonus' => 3, 'description' => 'ゴブリン見習いが持つ実戦用の剣', 'is_shop_item' => false],
            ['name' => '親分の腕輪', 'type' => 'accessory', 'rarity' => 'rare', 'required_level' => 8, 'hp_bonus' => 13, 'str_bonus' => 6, 'def_bonus' => 4, 'description' => '小鬼の森の親分が身につけていた腕輪', 'is_shop_item' => false],
            // 古びた洞窟
            ['name' => 'コウモリの羽飾り', 'type' => 'accessory', 'rarity' => 'rare', 'required_level' => 10, 'agi_bonus' => 10, 'luk_bonus' => 4, 'description' => '洞窟コウモリの羽を使った飾り', 'is_shop_item' => false],
            ['name' => '岩皮の鎧', 'type' => 'armor', 'rarity' => 'rare', 'required_level' => 11, 'hp_bonus' => 19, 'def_bonus' => 18, 'agi_bonus' => -2, 'description' => '岩トカゲの硬い皮で作られた鎧', 'is_shop_item' => false],
            ['name' => '骨の短剣', 'type' => 'weapon', 'rarity' => 'rare', 'required_level' => 12, 'str_bonus' => 20, 'agi_bonus' => 4, 'description' => '骨を削り出して作られた短剣', 'is_shop_item' => false],
            ['name' => '番人の護符', 'type' => 'accessory', 'rarity' => 'epic', 'required_level' => 14, 'hp_bonus' => 25, 'str_bonus' => 5, 'def_bonus' => 6, 'mag_bonus' => 5, 'luk_bonus' => 5, 'description' => '洞窟の番人の力を宿す護符', 'is_shop_item' => false],
            // 伝説職解放アイテム（素材扱い、非売品）
            ['name' => '英雄の証', 'type' => 'material', 'rarity' => 'epic', 'price' => 0, 'required_level' => 1, 'description' => '真の英雄であることを証明する証', 'is_shop_item' => false],
            ['name' => '深淵の核', 'type' => 'material', 'rarity' => 'epic', 'price' => 0, 'required_level' => 1, 'description' => '深淵の奥底で凝縮された闇の核', 'is_shop_item' => false],
            ['name' => '古代核', 'type' => 'material', 'rarity' => 'epic', 'price' => 0, 'required_level' => 1, 'description' => '古代の錬成炉を動かしていた動力源', 'is_shop_item' => false],
            ['name' => '竜神の鱗', 'type' => 'material', 'rarity' => 'epic', 'price' => 0, 'required_level' => 1, 'description' => '竜王から剥がれ落ちた神々しい鱗', 'is_shop_item' => false],
            ['name' => '時空結晶', 'type' => 'material', 'rarity' => 'epic', 'price' => 0, 'required_level' => 1, 'description' => '次元の歪みから生み出された神秘の結晶', 'is_shop_item' => false],
        ];

        foreach ($items as $data) {
            if (in_array($data['type'] ?? null, ['weapon', 'armor', 'accessory'], true)) {
                continue;
            }

            Item::updateOrCreate(['name' => $data['name']], $data);
        }
    }
}
