<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class Phase2Seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // エリアの作成
        $area = \App\Models\Area::updateOrCreate(
            ['name' => '深き森の廃墟'],
            [
                'slug' => 'deep_forest',
                'description' => '鬱蒼とした森の奥深くにある古代の遺跡。中級者向けの危険なモンスターが潜む。',
                'recommended_level_min' => 20,
                'recommended_level_max' => 50,
                'unlock_order' => 2,
            ]
        );

        // モンスターの作成
        $enemies = [
            [
                'area_id' => $area->id,
                'name' => 'オーク',
                'level' => 20,
                'max_hp' => 800,
                'str' => 120,
                'def' => 60,
                'agi' => 20,
                'exp_reward' => 250,
                'gold_reward' => 80,
                'job_exp_reward' => 1,
                'is_boss' => false,
            ],
            [
                'area_id' => $area->id,
                'name' => 'ポイズントード',
                'level' => 25,
                'max_hp' => 600,
                'str' => 80,
                'def' => 150,
                'agi' => 40,
                'exp_reward' => 300,
                'gold_reward' => 50,
                'job_exp_reward' => 1,
                'is_boss' => false,
            ],
            [
                'area_id' => $area->id,
                'name' => '亡霊剣士',
                'level' => 35,
                'max_hp' => 1200,
                'str' => 250,
                'def' => 100,
                'agi' => 120,
                'exp_reward' => 500,
                'gold_reward' => 150,
                'job_exp_reward' => 2,
                'is_boss' => false,
            ],
            [
                'area_id' => $area->id,
                'name' => 'デュラハン',
                'level' => 50,
                'max_hp' => 5000,
                'str' => 400,
                'def' => 250,
                'agi' => 150,
                'exp_reward' => 2000,
                'gold_reward' => 1000,
                'job_exp_reward' => 5,
                'is_boss' => true,
            ],
        ];

        foreach ($enemies as $enemyData) {
            \App\Models\Enemy::updateOrCreate(
                ['name' => $enemyData['name'], 'area_id' => $area->id],
                $enemyData
            );
        }

        // 旧装備データは現行の武器合成・育成マスタへ統合済みのため作成しません。
    }

    private function addDrop($enemyName, $itemName, $dropRate)
    {
        $enemy = \App\Models\Enemy::where('name', $enemyName)->first();
        $item = \App\Models\Item::where('name', $itemName)->first();

        if ($enemy && $item) {
            \App\Models\EnemyDrop::updateOrCreate(
                ['enemy_id' => $enemy->id, 'item_id' => $item->id],
                ['drop_rate' => $dropRate] // 10000分率
            );
        }
    }
}
