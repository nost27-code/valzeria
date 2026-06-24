<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EQUIPMENT_FRAGMENT_CODE = 'MAT_EQUIPMENT_FRAGMENT';
    private const EQUIPMENT_FRAGMENT_NAME = '装備の欠片';

    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $values = [
            'name' => self::EQUIPMENT_FRAGMENT_NAME,
            'category' => '装備共通素材',
            'rarity' => 'N',
            'main_use' => '装備の進化・強化',
            'city_id' => null,
            'dungeon_id' => null,
            'source_enemy_id' => null,
        ];

        if (Schema::hasColumn('materials', 'material_type')) {
            $values['material_type'] = 'equipment_common';
        }
        if (Schema::hasColumn('materials', 'rank_tier')) {
            $values['rank_tier'] = 1;
        }

        DB::table('materials')
            ->where('material_code', self::EQUIPMENT_FRAGMENT_CODE)
            ->update($values);
    }

    public function down(): void
    {
        // 共通素材のスコープ補正なので戻しません。
    }
};
