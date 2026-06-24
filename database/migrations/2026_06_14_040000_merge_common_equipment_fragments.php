<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TARGET_CODE = 'MAT_EQUIPMENT_FRAGMENT';
    private const TARGET_NAME = '装備の欠片';
    private const OLD_CODES = ['WEV0001', '5001', 'ACC0001', 'MAT_WEAPON_FRAGMENT'];

    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $this->ensureTargetMaterial();
        $this->mergeCharacterMaterials();
        $this->mergeMaterialDrops();
        $this->replaceEvolutionRecipeFragments();
        $this->replaceWeaponEnhancementFragments();
        $this->updateEvolutionStoneDescriptions();
    }

    public function down(): void
    {
        // Player quantities cannot be split back into weapon/armor/accessory safely.
    }

    private function ensureTargetMaterial(): void
    {
        $now = now();
        $payload = [
            'name' => self::TARGET_NAME,
            'category' => '装備共通進化素材',
            'rarity' => 'N',
            'element' => null,
            'main_use' => '武器・防具・装飾品の進化',
            'npc_sale_price' => 0,
            'is_tradable' => false,
            'city_id' => null,
            'dungeon_id' => null,
            'source_enemy_id' => null,
            'updated_at' => $now,
            'created_at' => $now,
        ];

        foreach ([
            'drop_rate' => 0,
            'drop_first_clear_only' => false,
            'drop_timing' => null,
            'material_type' => 'equipment_common',
            'category_id' => 'equipment_common',
            'rank_tier' => 1,
            'is_consumable' => true,
            'obtain_method' => '装備分解・通常探索・素材交換所で入手。旧「武器/防具/装飾の欠片」を統合した共通素材。',
        ] as $column => $value) {
            if (Schema::hasColumn('materials', $column)) {
                $payload[$column] = $value;
            }
        }

        DB::table('materials')->updateOrInsert(
            ['material_code' => self::TARGET_CODE],
            $payload
        );
    }

    private function mergeCharacterMaterials(): void
    {
        if (!Schema::hasTable('character_materials')) {
            return;
        }

        $targetId = DB::table('materials')->where('material_code', self::TARGET_CODE)->value('id');
        if (!$targetId) {
            return;
        }

        $oldIds = DB::table('materials')
            ->whereIn('material_code', self::OLD_CODES)
            ->pluck('id')
            ->filter(fn ($id) => (int) $id !== (int) $targetId)
            ->values();

        if ($oldIds->isEmpty()) {
            return;
        }

        $rows = DB::table('character_materials')
            ->select('character_id', DB::raw('SUM(quantity) as total_quantity'))
            ->whereIn('material_id', $oldIds)
            ->groupBy('character_id')
            ->get();

        foreach ($rows as $row) {
            $existing = DB::table('character_materials')
                ->where('character_id', $row->character_id)
                ->where('material_id', $targetId)
                ->first();

            if ($existing) {
                DB::table('character_materials')
                    ->where('id', $existing->id)
                    ->update([
                        'quantity' => (int) $existing->quantity + (int) $row->total_quantity,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('character_materials')->insert([
                    'character_id' => $row->character_id,
                    'material_id' => $targetId,
                    'quantity' => (int) $row->total_quantity,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('character_materials')->whereIn('material_id', $oldIds)->delete();
    }

    private function mergeMaterialDrops(): void
    {
        if (!Schema::hasTable('material_drops')) {
            return;
        }

        $targetId = DB::table('materials')->where('material_code', self::TARGET_CODE)->value('id');
        $oldIds = DB::table('materials')
            ->whereIn('material_code', self::OLD_CODES)
            ->pluck('id')
            ->filter(fn ($id) => (int) $id !== (int) $targetId)
            ->values();

        if (!$targetId || $oldIds->isEmpty()) {
            return;
        }

        $drops = DB::table('material_drops')->whereIn('material_id', $oldIds)->get();
        foreach ($drops as $drop) {
            $existing = DB::table('material_drops')
                ->where('enemy_id', $drop->enemy_id)
                ->where('material_id', $targetId)
                ->first();

            if ($existing) {
                DB::table('material_drops')
                    ->where('id', $existing->id)
                    ->update([
                        'drop_rate' => max((float) $existing->drop_rate, (float) $drop->drop_rate),
                        'is_active' => (bool) $existing->is_active || (bool) $drop->is_active,
                        'updated_at' => now(),
                    ]);

                DB::table('material_drops')->where('id', $drop->id)->delete();
            } else {
                DB::table('material_drops')
                    ->where('id', $drop->id)
                    ->update([
                        'material_id' => $targetId,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    private function replaceEvolutionRecipeFragments(): void
    {
        $now = now();

        if (Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            DB::table('weapon_evolution_recipe_ingredients')
                ->whereIn('ingredient_id', ['WEV0001', 'MAT_WEAPON_FRAGMENT'])
                ->update([
                    'ingredient_id' => self::TARGET_CODE,
                    'ingredient_name' => self::TARGET_NAME,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('armor_evolution_recipe_ingredients')) {
            DB::table('armor_evolution_recipe_ingredients')
                ->where('material_id', '5001')
                ->update([
                    'material_id' => self::TARGET_CODE,
                    'material_name' => self::TARGET_NAME,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            DB::table('accessory_evolution_recipe_ingredients')
                ->where('material_code', 'ACC0001')
                ->update([
                    'material_code' => self::TARGET_CODE,
                    'material_name' => self::TARGET_NAME,
                    'updated_at' => $now,
                ]);
        }
    }

    private function replaceWeaponEnhancementFragments(): void
    {
        if (!Schema::hasTable('weapon_enhancement_recipes')) {
            return;
        }

        $replacements = [
            'MAT_WEAPON_FRAGMENT' => self::TARGET_CODE,
            'WEV0001' => self::TARGET_CODE,
            '武器の欠片' => self::TARGET_NAME,
        ];

        foreach ($replacements as $from => $to) {
            DB::table('weapon_enhancement_recipes')
                ->where('materials', 'like', '%' . $from . '%')
                ->update([
                    'materials' => DB::raw("REPLACE(materials, " . DB::getPdo()->quote($from) . ', ' . DB::getPdo()->quote($to) . ')'),
                    'updated_at' => now(),
                ]);
        }
    }

    private function updateEvolutionStoneDescriptions(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasColumn('materials', 'obtain_method')) {
            return;
        }

        DB::table('materials')
            ->whereIn('material_code', [
                'MAT_WEAPON_EVOLUTION_STONE',
                'MAT_ARMOR_EVOLUTION_STONE',
                'MAT_ACCESSORY_EVOLUTION_STONE',
            ])
            ->update([
                'obtain_method' => '素材交換所で装備の欠片10個と交換',
                'updated_at' => now(),
            ]);
    }
};
