<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EquipmentAffixSuffixSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $weaponRows = [
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

        $armorRows = [
            ['beast', '獣避', 10],
            ['undead', '屍除', 20],
            ['dragon', '竜鱗', 30],
            ['demon', '魔除', 40],
            ['aquatic', '水護', 50],
            ['flying', '翼避', 60],
            ['insect', '蟲除', 70],
            ['machine', '機護', 80],
            ['slime', '粘避', 90],
            ['soldier', '兵護', 100],
            ['mage', '術避', 110],
            ['spirit', '霊護', 120],
        ];

        $hasGenericColumns = Schema::hasColumn('equipment_affix_suffixes', 'item_type')
            && Schema::hasColumn('equipment_affix_suffixes', 'effect_type')
            && Schema::hasColumn('equipment_affix_suffixes', 'base_effect_rate');

        if (!$hasGenericColumns) {
            foreach ($weaponRows as [$key, $name, $order]) {
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

            return;
        }

        foreach ($weaponRows as [$key, $name, $order]) {
            DB::table('equipment_affix_suffixes')->updateOrInsert(
                ['item_type' => 'weapon', 'effect_type' => 'killer_damage', 'species_key' => $key],
                [
                    'name' => $name,
                    'base_killer_rate' => 0.0500,
                    'base_effect_rate' => 0.0500,
                    'roll_weight' => 100,
                    'is_active' => true,
                    'sort_order' => $order,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        foreach ($armorRows as [$key, $name, $order]) {
            DB::table('equipment_affix_suffixes')->updateOrInsert(
                ['item_type' => 'armor', 'effect_type' => 'species_resist', 'species_key' => $key],
                [
                    'name' => $name,
                    'base_killer_rate' => 0,
                    'base_effect_rate' => 0.0400,
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
