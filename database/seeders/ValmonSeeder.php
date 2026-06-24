<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\ValmonMaster;
use App\Models\ValmonSpawnRegion;
use Illuminate\Database\Seeder;

class ValmonSeeder extends Seeder
{
    public function run(): void
    {
        $imagePaths = [
            'rapil' => 'images/valmon/valmon01.webp',
            'pengle' => 'images/valmon/valmon02.webp',
            'leafy' => 'images/valmon/valmon03.webp',
            'dracol' => 'images/valmon/valmon04.webp',
            'gangoro' => 'images/valmon/valmon05.webp',
            'sunamogu' => 'images/valmon/valmon06.webp',
            'bolt_nya' => 'images/valmon/valmon07.webp',
            'kuropuru' => 'images/valmon/valmon08.webp',
            'piyoram' => 'images/valmon/valmon09.webp',
            'aquaron' => 'images/valmon/valmon10.webp',
            'morikoro' => 'images/valmon/valmon11.webp',
            'koorisu' => 'images/valmon/valmon12.webp',
            'sabock' => 'images/valmon/valmon13.webp',
            'rockam' => 'images/valmon/valmon14.webp',
            'lumi_cube' => 'images/valmon/valmon15.webp',
            'nekmol' => 'images/valmon/valmon16.webp',
            'tsubasaur' => 'images/valmon/valmon17.webp',
            'shellx' => 'images/valmon/valmon18.webp',
            'miramy' => 'images/valmon/valmon19.webp',
            'abysslim' => 'images/valmon/valmon20.webp',
        ];

        $valmons = [
            ['rapil', 'ソラキツネ', '大きな兎耳を持つ明るい毛色の小狐型ヴァルモン。序盤素材集めに強く、最初の相棒にも向いている。', '大耳小狐型', 'normal', '通常素材', true, 10],
            ['pengle', 'マリペン', '水兵帽をかぶったペンギン型ヴァルモン。水辺や氷の気配に敏感で、冒険の継続を助ける。', '水兵ペンギン型', 'normal', '水・氷素材', true, 20],
            ['leafy', 'リーフィン', '葉飾りの緑鳥型ヴァルモン。卵の気配を探るのが得意で、図鑑埋めの初期相棒として優秀。', '葉飾り鳥型', 'normal', '卵・自然素材', true, 30],
            ['dracol', 'ギアラクーン', '機工ヘルメットをかぶったタヌキ型ヴァルモン。鉱石や機械系素材の気配に反応する。', '機工タヌキ型', 'normal', '鉱石・機械素材', false, 40],
            ['gangoro', 'ロックゴレム', '苔と石でできた小型ゴーレム。硬い体で危険な探索を支える護衛型の相棒。', '小型ゴーレム型', 'normal', '鉱石・防具素材', false, 50],
            ['sunamogu', 'ルナミャオ', '月模様を持つ黒猫型ヴァルモン。幸運を呼ぶとされ、通常ドロップの気配に敏感。', '月紋黒猫型', 'uncommon', '幸運・魔法素材', false, 60],
            ['bolt_nya', 'バットニャ', '蝙蝠羽を持つ黒猫型ヴァルモン。闇素材を探し、低確率で先制の気配を知らせる。', '蝙蝠猫型', 'uncommon', '闇素材', false, 70],
            ['kuropuru', 'アクアプル', 'ぷるぷるした水棲小獣型ヴァルモン。水や湿地の素材を見つけやすい。', '水棲ぷる獣型', 'normal', '水・湿地素材', false, 80],
            ['piyoram', 'ミツビー', '蜂と妖精を合わせた小型ヴァルモン。育成の導入役として扱いやすい相棒。', '蜜蜂妖精型', 'normal', '草花・育成素材', false, 90],
            ['aquaron', 'ランタンホロウ', 'ランタンを持つ小さな幽霊型ヴァルモン。宝箱や不思議な気配を探るのが得意。', 'ランタン幽霊型', 'uncommon', '宝箱・魔法素材', false, 100],
            ['morikoro', 'マンドラン', '頭に薬草の芽がある小さなマンドラゴラ型ヴァルモン。薬草や魔草の素材集めに向く。', '薬草マンドラゴラ型', 'normal', '薬草・魔草素材', false, 110],
            ['koorisu', 'コーラルン', '珊瑚の角を持つ小さな海獣型ヴァルモン。水辺、貝、珊瑚系素材の気配に敏感。', '珊瑚海獣型', 'normal', '水・貝・珊瑚素材', false, 120],
            ['sabock', 'フェアモス', '光る羽を持つ蛾・妖精型ヴァルモン。精霊や魔草の素材を探しやすい。', '蛾妖精型', 'normal', '精霊・魔草素材', false, 130],
            ['rockam', 'アイアンモール', '鉄兜をかぶったモグラ型ヴァルモン。鉱石や金属片の採掘が得意。', '鉄兜モグラ型', 'normal', '鉱石・金属素材', false, 140],
            ['lumi_cube', 'フロウル', '氷毛をまとう狼型ヴァルモン。危険を察知し、氷雪地帯の素材に強い。', '氷毛狼型', 'uncommon', '氷・危険察知素材', false, 150],
            ['nekmol', 'サンドリザ', '砂色の小トカゲ型ヴァルモン。砂漠素材に強く、長めの探索を支える。', '砂トカゲ型', 'normal', '砂漠素材', false, 160],
            ['tsubasaur', 'クロックオウル', '歯車羽のフクロウ型ヴァルモン。卵や未発見の気配を探る知的な相棒。', '歯車フクロウ型', 'uncommon', '卵・魔導素材', false, 170],
            ['shellx', 'ナイトメアコ', '紫炎をまとった小さな魔獣型ヴァルモン。闇素材やレアな気配に敏感。', '紫炎魔獣型', 'rare', '闇・レア素材', false, 180],
            ['miramy', 'セレスコーン', '白い小さなユニコーン型ヴァルモン。回復の気配をまとった希少な相棒。', '小型ユニコーン型', 'rare', '回復・天界素材', false, 190],
            ['abysslim', 'レドラコ', '赤い幼竜型ヴァルモン。火、竜、魔王城系素材の気配に強い終盤の目玉枠。', '赤幼竜型', 'super_rare', '火・竜・魔王城素材', false, 200],
        ];

        foreach ($valmons as [$key, $name, $description, $type, $rarity, $category, $starter, $sort]) {
            ValmonMaster::updateOrCreate(
                ['valmon_key' => $key],
                [
                    'name' => $name,
                    'description' => $description,
                    'image_path' => $imagePaths[$key] ?? null,
                    'silhouette_type' => $type,
                    'rarity' => $rarity,
                    'base_find_material_category' => $category,
                    'is_starter' => $starter,
                    'is_active' => true,
                    'sort_order' => $sort,
                ]
            );
        }

        ValmonSpawnRegion::query()->update(['is_active' => false]);

        $regions = [
            '王都アークレア' => ['rapil', 'pengle', 'leafy', 'piyoram', 'morikoro'],
            '港町マリネス' => ['pengle', 'kuropuru', 'koorisu'],
            '精霊の森エルフィア' => ['rapil', 'leafy', 'kuropuru', 'piyoram', 'sabock'],
            '鍛冶街グランベルグ' => ['dracol', 'gangoro', 'rockam'],
            '雪原の町フロストリア' => ['pengle', 'lumi_cube'],
            '砂漠の宿場サンドラ' => ['gangoro', 'nekmol'],
            '魔導学院ルミナス' => ['dracol', 'sunamogu', 'aquaron', 'tsubasaur'],
            '死霊街ネクロム' => ['sunamogu', 'bolt_nya', 'aquaron', 'shellx'],
            '天空神殿セレスティア' => ['leafy', 'sunamogu', 'aquaron', 'miramy'],
            '魔王城ヴァルゼリア' => ['bolt_nya', 'abysslim'],
        ];

        $weightByRarity = [
            'normal' => 7000,
            'uncommon' => 2200,
            'rare' => 700,
            'super_rare' => 100,
        ];

        foreach ($regions as $cityName => $keys) {
            $city = City::where('name', $cityName)->first();
            if (!$city) {
                continue;
            }

            foreach ($keys as $key) {
                $valmon = ValmonMaster::where('valmon_key', $key)->first();
                if (!$valmon) {
                    continue;
                }

                ValmonSpawnRegion::updateOrCreate(
                    ['city_id' => $city->id, 'valmon_master_id' => $valmon->id],
                    [
                        'spawn_weight' => $weightByRarity[$valmon->rarity] ?? 1000,
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
