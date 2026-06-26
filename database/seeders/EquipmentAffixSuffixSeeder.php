<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EquipmentAffixSuffixSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rows = [
            ['beast', '獣牙', 10],
            ['undead', '屍祓', 20],
            ['dragon', '竜断', 30],
            ['demon', '魔祓', 40],
            ['aquatic', '水断', 50],
            ['flying', '翼落', 60],
            ['insect', '蟲砕', 70],
            ['machine', '機砕', 80],
            ['slime', '粘断', 90],
            ['soldier', '兵崩', 100],
            ['mage', '術封', 110],
            ['spirit', '霊祓', 120],
        ];

        foreach ($rows as [$key, $name, $order]) {
            DB::table('equipment_affix_suffixes')->updateOrInsert(
                ['species_key' => $key],
                [
                    'name' => $name,
                    'base_killer_rate' => 0.0500,
                    'roll_weight' => 100,
                    'is_active' => true,
                    'sort_order' => $order,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}
