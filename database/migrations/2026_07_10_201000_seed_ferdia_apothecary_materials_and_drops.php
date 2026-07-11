<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $materials = [
            'MAT_FERDIA_BLUE_LIFE_LEAF' => ['青命草の葉', '一般'],
            'MAT_FERDIA_CLEARSTREAM_DROP' => ['清流の雫', '一般'],
            'MAT_FERDIA_GUARDTREE_RESIN' => ['守樹の樹脂', 'やや希少'],
            'MAT_FERDIA_HEMOSTATIC_MOSS' => ['止血苔', '一般'],
            'MAT_FERDIA_DETOX_GALL' => ['毒抜きの胆', 'やや希少'],
            'MAT_FERDIA_LIFEROOT' => ['命脈根', '希少'],
        ];
        foreach ($materials as $code => [$name, $rarity]) {
            DB::table('materials')->updateOrInsert(['material_code' => $code], [
                'name' => $name, 'category' => 'フェルディア薬素材', 'rarity' => $rarity, 'element' => null,
                'main_use' => '薬屋の探索補助品調合', 'npc_sale_price' => 0, 'is_tradable' => true,
                'city_id' => null, 'dungeon_id' => null, 'source_enemy_id' => null,
                'drop_rate' => 0, 'drop_first_clear_only' => false, 'drop_timing' => null,
                'material_type' => 'brewing', 'category_id' => 'ferdia_apothecary', 'rank_tier' => 1,
                'is_consumable' => true, 'obtain_method' => 'フェルディアの探索地で入手', 'updated_at' => $now, 'created_at' => $now,
            ]);
        }

        $codes = array_keys($materials);
        $codes = array_merge($codes, [
            'MAT_BR_WPN_HOLY_ANCIENT', 'MAT_BR_WPN_GALE_ANCIENT', 'MAT_BR_WPN_DARK_ANCIENT',
            'MAT_BR_ARM_HEAVY_ANCIENT', 'MAT_BR_ARM_ARCANE_ANCIENT', 'MAT_BR_ARM_LIGHT_ANCIENT', 'MAT_BR_ARM_TRAVELER_ANCIENT',
        ]);
        $materialIds = DB::table('materials')->whereIn('material_code', $codes)->pluck('id', 'material_code');

        $dropMap = [
            1001 => ['スライム' => [['MAT_FERDIA_HEMOSTATIC_MOSS', 10]], '獣' => [['MAT_FERDIA_BLUE_LIFE_LEAF', 12]], 'other' => [['MAT_FERDIA_BLUE_LIFE_LEAF', 10]]],
            1002 => ['スライム' => [['MAT_FERDIA_HEMOSTATIC_MOSS', 8]], '獣' => [['MAT_FERDIA_BLUE_LIFE_LEAF', 10]], 'other' => [['MAT_FERDIA_HEMOSTATIC_MOSS', 8]]],
            1003 => ['スライム' => [['MAT_FERDIA_BLUE_LIFE_LEAF', 8]], '獣' => [['MAT_FERDIA_BLUE_LIFE_LEAF', 12]], 'other' => [['MAT_FERDIA_BLUE_LIFE_LEAF', 8]]],
            1004 => ['スライム' => [['MAT_FERDIA_CLEARSTREAM_DROP', 12]], '獣' => [['MAT_FERDIA_HEMOSTATIC_MOSS', 8]], 'other' => [['MAT_FERDIA_CLEARSTREAM_DROP', 12]]],
            1005 => ['スライム' => [['MAT_FERDIA_HEMOSTATIC_MOSS', 10]], '獣' => [['MAT_FERDIA_BLUE_LIFE_LEAF', 6]], 'other' => [['MAT_FERDIA_HEMOSTATIC_MOSS', 8]]],
            1006 => ['スライム' => [['MAT_FERDIA_HEMOSTATIC_MOSS', 8]], '獣' => [['MAT_FERDIA_BLUE_LIFE_LEAF', 6]], 'other' => []],
            1007 => ['スライム' => [['MAT_FERDIA_BLUE_LIFE_LEAF', 8]], '獣' => [['MAT_FERDIA_BLUE_LIFE_LEAF', 10]], 'other' => [['MAT_FERDIA_HEMOSTATIC_MOSS', 6]]],
            1008 => ['スライム' => [['MAT_FERDIA_BLUE_LIFE_LEAF', 10]], '獣' => [['MAT_FERDIA_BLUE_LIFE_LEAF', 12]], 'other' => [['MAT_FERDIA_BLUE_LIFE_LEAF', 10]]],
            1009 => ['スライム' => [['MAT_FERDIA_CLEARSTREAM_DROP', 10]], '獣' => [['MAT_FERDIA_DETOX_GALL', 5]], 'other' => [['MAT_FERDIA_CLEARSTREAM_DROP', 10], ['MAT_FERDIA_DETOX_GALL', 3]]],
            1010 => ['スライム' => [['MAT_FERDIA_GUARDTREE_RESIN', 10]], '獣' => [['MAT_FERDIA_DETOX_GALL', 5]], 'other' => [['MAT_FERDIA_GUARDTREE_RESIN', 10]]],
            1011 => ['スライム' => [['MAT_FERDIA_GUARDTREE_RESIN', 12]], '獣' => [['MAT_FERDIA_BLUE_LIFE_LEAF', 8]], '巨人' => [['MAT_FERDIA_LIFEROOT', 2]], 'other' => [['MAT_FERDIA_LIFEROOT', 3]]],
            1012 => ['スライム' => [['MAT_FERDIA_GUARDTREE_RESIN', 10]], '獣' => [['MAT_FERDIA_BLUE_LIFE_LEAF', 8]], '巨人' => [['MAT_FERDIA_LIFEROOT', 2.5]], 'other' => [['MAT_FERDIA_LIFEROOT', 3.5]]],
            1013 => ['スライム' => [['MAT_FERDIA_CLEARSTREAM_DROP', 8]], '獣' => [['MAT_FERDIA_BLUE_LIFE_LEAF', 6]], '巨人' => [['MAT_FERDIA_LIFEROOT', 2.5]], 'other' => [['MAT_FERDIA_LIFEROOT', 3]]],
        ];
        $ancient = [1001 => 'MAT_BR_ARM_TRAVELER_ANCIENT', 1002 => 'MAT_BR_ARM_LIGHT_ANCIENT', 1003 => 'MAT_BR_WPN_GALE_ANCIENT', 1004 => 'MAT_BR_ARM_ARCANE_ANCIENT', 1005 => 'MAT_BR_ARM_HEAVY_ANCIENT', 1006 => 'MAT_BR_WPN_HOLY_ANCIENT', 1007 => 'MAT_BR_ARM_HEAVY_ANCIENT', 1008 => 'MAT_BR_ARM_TRAVELER_ANCIENT', 1009 => 'MAT_BR_ARM_ARCANE_ANCIENT', 1010 => 'MAT_BR_ARM_LIGHT_ANCIENT', 1011 => 'MAT_BR_WPN_GALE_ANCIENT', 1012 => 'MAT_BR_WPN_HOLY_ANCIENT', 1013 => 'MAT_BR_WPN_DARK_ANCIENT'];
        foreach ($dropMap as $areaId => $types) {
            $enemies = DB::table('enemies')->where('area_id', $areaId)->where('is_boss', false)->get();
            foreach ($enemies as $enemy) {
                $type = (string) $enemy->type_name;
                $entries = array_key_exists($type, $types) ? $types[$type] : $types['other'];
                if (in_array($type, ['人型', '巨人'], true)) $entries = $types[$type] ?? [];
                if ($type === '人型' && isset($materialIds[$ancient[$areaId]])) $entries[] = [$ancient[$areaId], 0.38];
                if ($type === '巨人' && isset($materialIds[$ancient[$areaId]])) $entries[] = [$ancient[$areaId], 0.30];
                foreach ($entries as [$code, $rate]) {
                    if (!isset($materialIds[$code])) continue;
                    DB::table('material_drops')->updateOrInsert(['enemy_id' => $enemy->id, 'material_id' => $materialIds[$code]], ['drop_rate' => $rate, 'drop_first_clear_only' => false, 'drop_timing' => null, 'is_active' => true, 'updated_at' => $now, 'created_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        $codes = ['MAT_FERDIA_BLUE_LIFE_LEAF', 'MAT_FERDIA_CLEARSTREAM_DROP', 'MAT_FERDIA_GUARDTREE_RESIN', 'MAT_FERDIA_HEMOSTATIC_MOSS', 'MAT_FERDIA_DETOX_GALL', 'MAT_FERDIA_LIFEROOT'];
        $ids = DB::table('materials')->whereIn('material_code', $codes)->pluck('id');
        DB::table('material_drops')->whereIn('material_id', $ids)->delete();
        DB::table('materials')->whereIn('material_code', $codes)->delete();
    }
};
