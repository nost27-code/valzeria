<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StarTreeTowerTitleSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('titles')) {
            return;
        }

        foreach ($this->titles() as $floor => $title) {
            DB::table('titles')->updateOrInsert(
                [
                    'unlock_type' => 'tower_floor_clear',
                    'target_type' => 'tower_floor',
                    'target_id' => (string) $floor,
                ],
                [
                    'category' => '星樹の塔',
                    'rarity' => $title['rarity'],
                    'name' => $title['name'],
                    'description' => "星樹の塔{$floor}階を踏破した証。",
                    'hint' => "星樹の塔{$floor}階を踏破する",
                    'source_master' => 'star_tree_tower',
                    'display_order' => 1100 + $floor,
                    'is_hidden' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    /**
     * @return array<int, array{name:string, rarity:string}>
     */
    private function titles(): array
    {
        return [
            10 => ['name' => '星梯の一歩', 'rarity' => 'normal'],
            20 => ['name' => '若葉を越えし者', 'rarity' => 'normal'],
            30 => ['name' => '風枝の踏破者', 'rarity' => 'rare'],
            40 => ['name' => '星灯を掲げる者', 'rarity' => 'rare'],
            50 => ['name' => '天冠へ届く者', 'rarity' => 'epic'],
            60 => ['name' => '星天の登攀者', 'rarity' => 'epic'],
            70 => ['name' => '高枝を渡る者', 'rarity' => 'epic'],
            80 => ['name' => '天葉の導き手', 'rarity' => 'legendary'],
            90 => ['name' => '星梯の極致', 'rarity' => 'legendary'],
            100 => ['name' => '星樹の頂に立つ者', 'rarity' => 'legendary'],
        ];
    }
}
