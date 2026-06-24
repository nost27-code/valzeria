<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\City;
use App\Models\Area;
use App\Models\Character;

class CitySeeder extends Seeder
{
    public function run(): void
    {
        $cities = [
            [
                'name' => '王都アークレア',
                'description' => '冒険者たちが集う始まりの街。基本的な施設がすべて揃っている。',
                'recommended_level_min' => 1,
                'recommended_level_max' => 15,
                'sort_order' => 10,
                'is_initial' => true,
            ],
            [
                'name' => '港町マリネス',
                'description' => '海に面した活気ある港町。海の魔物や水属性のダンジョンが近くにある。',
                'recommended_level_min' => 15,
                'recommended_level_max' => 29,
                'sort_order' => 20,
                'is_initial' => false,
            ],
            [
                'name' => '精霊の森エルフィア',
                'description' => '深い森の奥に広がる幻想的な拠点。魔法や精霊の力に満ちている。',
                'recommended_level_min' => 29,
                'recommended_level_max' => 43,
                'sort_order' => 30,
                'is_initial' => false,
            ],
            [
                'name' => '鍛冶街グランベルグ',
                'description' => '屈強な戦士と鍛冶師の街。常に炉の火が燃え盛っている。',
                'recommended_level_min' => 43,
                'recommended_level_max' => 57,
                'sort_order' => 40,
                'is_initial' => false,
            ],
            [
                'name' => '雪原の町フロストリア',
                'description' => '雪原に寄り添う寒冷地の中継町。氷雪に慣れた屈強なモンスターが徘徊する。',
                'recommended_level_min' => 57,
                'recommended_level_max' => 71,
                'sort_order' => 50,
                'is_initial' => false,
            ],
            [
                'name' => '砂漠の宿場サンドラ',
                'description' => '広大な砂漠のオアシスに築かれた旅の宿場。周囲には未知の遺跡が多く眠っている。',
                'recommended_level_min' => 71,
                'recommended_level_max' => 85,
                'sort_order' => 60,
                'is_initial' => false,
            ],
            [
                'name' => '魔導学院ルミナス',
                'description' => '魔法技術と知識が集まる学術拠点。高度な魔法を操る敵が待ち受ける。',
                'recommended_level_min' => 85,
                'recommended_level_max' => 99,
                'sort_order' => 70,
                'is_initial' => false,
            ],
            [
                'name' => '死霊街ネクロム',
                'description' => '魔界にほど近い、瘴気に包まれた不穏な街。アンデッドや高位魔族の巣窟。',
                'recommended_level_min' => 99,
                'recommended_level_max' => 113,
                'sort_order' => 80,
                'is_initial' => false,
            ],
            [
                'name' => '天空神殿セレスティア',
                'description' => '雲の上の浮遊島に築かれた神秘の神殿。天界の使いや聖なる獣が立ちはだかる。',
                'recommended_level_min' => 113,
                'recommended_level_max' => 127,
                'sort_order' => 90,
                'is_initial' => false,
            ],
            [
                'name' => '魔王城ヴァルゼリア',
                'description' => 'すべての元凶が潜む最終決戦の地。かつてない絶望と最強の敵が待ち受ける。',
                'recommended_level_min' => 127,
                'recommended_level_max' => 141,
                'sort_order' => 100,
                'is_initial' => false,
            ],
        ];

        foreach ($cities as $cityData) {
            City::updateOrCreate(
                ['name' => $cityData['name']],
                $cityData
            );
        }

        // 初期街のIDを取得
        $initialCity = City::where('is_initial', true)->first();

        if ($initialCity) {
            // 既存のすべてのエリアを王都アークレアに紐付け
            Area::whereNull('city_id')->update(['city_id' => $initialCity->id]);

            // 既存のすべてのキャラクターの現在地を王都アークレアに設定
            Character::whereNull('current_city_id')->update([
                'current_city_id' => $initialCity->id,
                'highest_city_id' => $initialCity->id,
            ]);
        }
    }
}
