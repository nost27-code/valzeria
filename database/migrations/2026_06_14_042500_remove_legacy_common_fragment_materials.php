<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TARGET_CODE = 'MAT_EQUIPMENT_FRAGMENT';
    private const TARGET_NAME = '装備の欠片';
    private const LEGACY_CODES = ['WEV0001', '5001', 'ACC0001', 'MAT_WEAPON_FRAGMENT'];

    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $targetId = $this->ensureTargetMaterial();
        if (!$targetId) {
            return;
        }

        $legacyIds = DB::table('materials')
            ->whereIn('material_code', self::LEGACY_CODES)
            ->where('id', '!=', $targetId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($legacyIds->isEmpty()) {
            return;
        }

        $this->mergeCharacterMaterials($targetId, $legacyIds);
        $this->mergeMaterialDrops($targetId, $legacyIds);
        $this->moveExplorationLootLogs($targetId, $legacyIds);
        $this->normalizeRecipeReferences();

        DB::table('materials')->whereIn('id', $legacyIds)->delete();
    }

    public function down(): void
    {
        // One-way cleanup: split fragment materials should not be recreated.
    }

    private function ensureTargetMaterial(): ?int
    {
        $now = now();
        $values = [
            'name' => self::TARGET_NAME,
            'category' => '装備共通素材',
            'rarity' => 'N',
            'element' => null,
            'main_use' => '装備進化・強化',
            'npc_sale_price' => 0,
            'is_tradable' => false,
            'city_id' => null,
            'dungeon_id' => null,
            'source_enemy_id' => null,
            'drop_rate' => 0,
            'drop_first_clear_only' => false,
            'drop_timing' => null,
            'updated_at' => $now,
            'created_at' => $now,
        ];

        if (Schema::hasColumn('materials', 'material_type')) {
            $values['material_type'] = 'equipment_common';
        }

        if (Schema::hasColumn('materials', 'category_id')) {
            $values['category_id'] = null;
        }

        if (Schema::hasColumn('materials', 'rank_tier')) {
            $values['rank_tier'] = 1;
        }

        if (Schema::hasColumn('materials', 'is_consumable')) {
            $values['is_consumable'] = true;
        }

        if (Schema::hasColumn('materials', 'obtain_method')) {
            $values['obtain_method'] = '装備分解・通常探索・素材交換所で入手。旧「武器/防具/装飾の欠片」を統合した共通素材。';
        }

        DB::table('materials')->updateOrInsert(
            ['material_code' => self::TARGET_CODE],
            $values
        );

        return DB::table('materials')->where('material_code', self::TARGET_CODE)->value('id');
    }

    private function mergeCharacterMaterials(int $targetId, $legacyIds): void
    {
        if (!Schema::hasTable('character_materials')) {
            return;
        }

        $rows = DB::table('character_materials')->whereIn('material_id', $legacyIds)->get();
        foreach ($rows as $row) {
            $existing = DB::table('character_materials')
                ->where('character_id', $row->character_id)
                ->where('material_id', $targetId)
                ->first();

            if ($existing) {
                DB::table('character_materials')
                    ->where('id', $existing->id)
                    ->update([
                        'quantity' => (int) $existing->quantity + (int) $row->quantity,
                        'updated_at' => now(),
                    ]);
                DB::table('character_materials')->where('id', $row->id)->delete();
                continue;
            }

            DB::table('character_materials')
                ->where('id', $row->id)
                ->update([
                    'material_id' => $targetId,
                    'updated_at' => now(),
                ]);
        }
    }

    private function mergeMaterialDrops(int $targetId, $legacyIds): void
    {
        if (!Schema::hasTable('material_drops')) {
            return;
        }

        $drops = DB::table('material_drops')->whereIn('material_id', $legacyIds)->get();
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
                        'drop_first_clear_only' => (bool) $existing->drop_first_clear_only && (bool) $drop->drop_first_clear_only,
                        'drop_timing' => $existing->drop_timing ?: $drop->drop_timing,
                        'is_active' => (bool) $existing->is_active || (bool) $drop->is_active,
                        'updated_at' => now(),
                    ]);
                DB::table('material_drops')->where('id', $drop->id)->delete();
                continue;
            }

            DB::table('material_drops')
                ->where('id', $drop->id)
                ->update([
                    'material_id' => $targetId,
                    'updated_at' => now(),
                ]);
        }
    }

    private function moveExplorationLootLogs(int $targetId, $legacyIds): void
    {
        if (!Schema::hasTable('exploration_loot_logs')) {
            return;
        }

        DB::table('exploration_loot_logs')
            ->whereIn('material_id', $legacyIds)
            ->update([
                'material_id' => $targetId,
                'updated_at' => now(),
            ]);
    }

    private function normalizeRecipeReferences(): void
    {
        if (Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            DB::table('weapon_evolution_recipe_ingredients')
                ->whereIn('ingredient_id', ['WEV0001', 'MAT_WEAPON_FRAGMENT'])
                ->update([
                    'ingredient_id' => self::TARGET_CODE,
                    'ingredient_name' => self::TARGET_NAME,
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('armor_evolution_recipe_materials')) {
            DB::table('armor_evolution_recipe_materials')
                ->where('material_id', '5001')
                ->update([
                    'material_id' => self::TARGET_CODE,
                    'material_name' => self::TARGET_NAME,
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('accessory_evolution_recipe_materials')) {
            DB::table('accessory_evolution_recipe_materials')
                ->where('material_code', 'ACC0001')
                ->update([
                    'material_code' => self::TARGET_CODE,
                    'material_name' => self::TARGET_NAME,
                    'updated_at' => now(),
                ]);
        }
    }
};
