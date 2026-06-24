<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Area;
use App\Models\Enemy;
use App\Models\PublicLog;

class Phase1Seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // エリアの作成
        $area1 = Area::firstOrCreate(['slug' => 'grassland'], [
            'name' => 'はじまりの草原',
            'description' => '街のすぐ外に広がる安全な草原',
            'recommended_level_min' => 1,
            'recommended_level_max' => 5,
            'unlock_order' => 1,
            'unlock_required_area_id' => null,
            'background_image' => 'facilities/dungeon_grassland.webp',
            'sort_order' => 10,
        ]);

        $area2 = Area::firstOrCreate(['slug' => 'goblin_forest'], [
            'name' => '小鬼の森',
            'description' => 'ゴブリンが棲み着く薄暗い森',
            'recommended_level_min' => 4,
            'recommended_level_max' => 10,
            'unlock_order' => 2,
            'unlock_required_area_id' => $area1->id,
            'background_image' => 'facilities/dungeon_goblin_forest.webp',
            'sort_order' => 20,
        ]);

        $area3 = Area::firstOrCreate(['slug' => 'old_cave'], [
            'name' => '古びた洞窟',
            'description' => '奥深くへと続く危険な洞窟',
            'recommended_level_min' => 10,
            'recommended_level_max' => 99, // 便宜上
            'unlock_order' => 3,
            'unlock_required_area_id' => $area2->id,
            'background_image' => 'facilities/dungeon_old_cave.webp',
            'sort_order' => 30,
        ]);

        // --- はじまりの草原の敵 ---
        Enemy::updateOrCreate(['area_id' => $area1->id, 'name' => 'スライム'], [
            'level' => 1, 'max_hp' => 15, 'str' => 3, 'def' => 1, 'agi' => 3, 'mag' => 1, 'luk' => 2,
            'exp_reward' => 8, 'gold_reward' => 6, 'appearance_weight' => 60, 'is_boss' => false
        ]);
        Enemy::updateOrCreate(['area_id' => $area1->id, 'name' => '迷いウサギ'], [
            'level' => 2, 'max_hp' => 20, 'str' => 4, 'def' => 2, 'agi' => 5, 'mag' => 1, 'luk' => 3,
            'exp_reward' => 10, 'gold_reward' => 8, 'appearance_weight' => 30, 'is_boss' => false
        ]);
        Enemy::updateOrCreate(['area_id' => $area1->id, 'name' => '草原コウモリ'], [
            'level' => 3, 'max_hp' => 25, 'str' => 5, 'def' => 2, 'agi' => 6, 'mag' => 2, 'luk' => 4,
            'exp_reward' => 12, 'gold_reward' => 10, 'appearance_weight' => 10, 'is_boss' => false
        ]);
        Enemy::updateOrCreate(['area_id' => $area1->id, 'name' => '草原の大スライム'], [
            'level' => 5, 'max_hp' => 50, 'str' => 8, 'def' => 5, 'agi' => 4, 'mag' => 5, 'luk' => 5,
            'exp_reward' => 40, 'gold_reward' => 35, 'appearance_weight' => 1, 'is_boss' => true
        ]);

        // --- 小鬼の森の敵 ---
        Enemy::firstOrCreate(['area_id' => $area2->id, 'name' => '小鬼'], [
            'level' => 4, 'max_hp' => 55, 'str' => 12, 'def' => 7, 'agi' => 8, 'mag' => 3, 'luk' => 4,
            'exp_reward' => 18, 'gold_reward' => 14, 'appearance_weight' => 50, 'is_boss' => false
        ]);
        Enemy::firstOrCreate(['area_id' => $area2->id, 'name' => '森ネズミ'], [
            'level' => 5, 'max_hp' => 50, 'str' => 10, 'def' => 6, 'agi' => 13, 'mag' => 2, 'luk' => 6,
            'exp_reward' => 20, 'gold_reward' => 15, 'appearance_weight' => 30, 'is_boss' => false
        ]);
        Enemy::firstOrCreate(['area_id' => $area2->id, 'name' => 'ゴブリン見習い'], [
            'level' => 6, 'max_hp' => 70, 'str' => 14, 'def' => 9, 'agi' => 9, 'mag' => 4, 'luk' => 5,
            'exp_reward' => 24, 'gold_reward' => 18, 'appearance_weight' => 20, 'is_boss' => false
        ]);
        Enemy::firstOrCreate(['area_id' => $area2->id, 'name' => '小鬼の森の親分'], [
            'level' => 10, 'max_hp' => 180, 'str' => 24, 'def' => 15, 'agi' => 12, 'mag' => 8, 'luk' => 10,
            'exp_reward' => 120, 'gold_reward' => 80, 'appearance_weight' => 1, 'is_boss' => true
        ]);

        // --- 古びた洞窟の敵 ---
        Enemy::firstOrCreate(['area_id' => $area3->id, 'name' => '洞窟コウモリ'], [
            'level' => 10, 'max_hp' => 90, 'str' => 20, 'def' => 12, 'agi' => 18, 'mag' => 10, 'luk' => 8,
            'exp_reward' => 36, 'gold_reward' => 25, 'appearance_weight' => 40, 'is_boss' => false
        ]);
        Enemy::firstOrCreate(['area_id' => $area3->id, 'name' => '岩トカゲ'], [
            'level' => 11, 'max_hp' => 120, 'str' => 22, 'def' => 20, 'agi' => 8, 'mag' => 5, 'luk' => 6,
            'exp_reward' => 42, 'gold_reward' => 28, 'appearance_weight' => 40, 'is_boss' => false
        ]);
        Enemy::firstOrCreate(['area_id' => $area3->id, 'name' => 'さまよう骨'], [
            'level' => 12, 'max_hp' => 110, 'str' => 25, 'def' => 16, 'agi' => 10, 'mag' => 12, 'luk' => 4,
            'exp_reward' => 48, 'gold_reward' => 32, 'appearance_weight' => 20, 'is_boss' => false
        ]);
        // 洞窟のボスは今回省略でも可、ダミーで作っておく
        Enemy::firstOrCreate(['area_id' => $area3->id, 'name' => 'スカルナイト'], [
            'level' => 15, 'max_hp' => 300, 'str' => 35, 'def' => 25, 'agi' => 18, 'mag' => 15, 'luk' => 12,
            'exp_reward' => 200, 'gold_reward' => 150, 'appearance_weight' => 1, 'is_boss' => true
        ]);

        // 公開ログの初期データ
        if (PublicLog::count() === 0) {
            PublicLog::create([
                'type' => 'system',
                'message' => '冒険都市ヴァルゼリアへようこそ。',
                'importance' => 1,
            ]);
            PublicLog::create([
                'type' => 'system',
                'message' => '初心者さんが冒険都市ヴァルゼリアに降り立ちました。',
                'importance' => 1,
            ]);
        }
    }
}
