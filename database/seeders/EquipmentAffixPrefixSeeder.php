<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EquipmentAffixPrefixSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rows = [
            ['life', '生命の', 'hp', 0.3500, 100, 10],
            ['power', '剛力の', 'str', 0.0700, 100, 20],
            ['sturdy', '堅牢の', 'def', 0.0700, 100, 30],
            ['arcane', '魔導の', 'mag', 0.0700, 100, 40],
            ['prayer', '祈祷の', 'spr', 0.0700, 100, 50],
            ['gale', '疾風の', 'agi', 0.0700, 100, 60],
            ['fortune', '豪運の', 'luk', 0.0800, 100, 70],
            ['tuning', '調律の', 'all', 0.0200, 80, 80],
        ];

        foreach ($rows as [$key, $name, $stat, $rate, $weight, $order]) {
            DB::table('equipment_affix_prefixes')->updateOrInsert(
                ['affix_key' => $key],
                [
                    'name' => $name,
                    'target_stat' => $stat,
                    'calculation_rate' => $rate,
                    'roll_weight' => $weight,
                    'is_active' => true,
                    'sort_order' => $order,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}
