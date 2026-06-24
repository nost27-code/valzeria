<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FRAGMENTS = [
        'MAT_EQUIPMENT_FRAGMENT' => ['name' => '装備の欠片', 'tier' => 1, 'rarity' => 'N'],
        'MAT_FINE_EQUIPMENT_FRAGMENT' => ['name' => '上質な装備の欠片', 'tier' => 2, 'rarity' => 'R'],
        'MAT_STRONG_EQUIPMENT_FRAGMENT' => ['name' => '強装備の欠片', 'tier' => 3, 'rarity' => 'SR'],
    ];

    private const CODE_MAP = [
        'WEV0001' => 'MAT_EQUIPMENT_FRAGMENT',
        '5001' => 'MAT_EQUIPMENT_FRAGMENT',
        'ACC0001' => 'MAT_EQUIPMENT_FRAGMENT',
        'MAT_WEAPON_FRAGMENT' => 'MAT_EQUIPMENT_FRAGMENT',

        'WEV0002' => 'MAT_FINE_EQUIPMENT_FRAGMENT',
        '5002' => 'MAT_FINE_EQUIPMENT_FRAGMENT',
        'ACC0002' => 'MAT_FINE_EQUIPMENT_FRAGMENT',
        'MAT_WEAPON_CRYSTAL' => 'MAT_FINE_EQUIPMENT_FRAGMENT',

        'WEV0003' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
        'MAT_WEAPON_CORE' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
        'WEV0004' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
        'WEV0005' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
        'WEV0006' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
        '5003' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
        '5004' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
        '5005' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
        '5050' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
        'ACC0003' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
        'ACC0004' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
        'ACC0005' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
        'ACC0006' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $this->ensureIngredientCodeColumns();
        $this->ensureTargetMaterials();
        $this->mergeCharacterMaterials();
        $this->mergeMaterialDrops();
        $this->replaceRecipeIngredients();
        $this->replaceEnhancementRecipeText();
    }

    public function down(): void
    {
        // Unified quantities cannot be safely split back into old category fragments.
    }

    private function ensureIngredientCodeColumns(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        if (Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            DB::statement('ALTER TABLE weapon_evolution_recipe_ingredients MODIFY ingredient_id VARCHAR(191) NOT NULL');
        }

        if (Schema::hasTable('armor_evolution_recipe_ingredients')) {
            DB::statement('ALTER TABLE armor_evolution_recipe_ingredients MODIFY material_id VARCHAR(191) NOT NULL');
        }
    }

    private function ensureTargetMaterials(): void
    {
        $now = now();
        foreach (self::FRAGMENTS as $code => $fragment) {
            $payload = [
                'name' => $fragment['name'],
                'category' => '装備共通素材',
                'rarity' => $fragment['rarity'],
                'element' => null,
                'main_use' => '装備の進化・強化',
                'npc_sale_price' => 0,
                'is_tradable' => false,
                'city_id' => null,
                'dungeon_id' => null,
                'source_enemy_id' => null,
                'updated_at' => $now,
            ];

            foreach ([
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
                'material_type' => 'equipment_common',
                'category_id' => 'equipment_common',
                'rank_tier' => $fragment['tier'],
                'is_consumable' => true,
                'obtain_method' => '探索・装備分解・素材交換所で入手。細分化された装備素材を統合した共通素材。',
            ] as $column => $value) {
                if (Schema::hasColumn('materials', $column)) {
                    $payload[$column] = $value;
                }
            }

            if (!DB::table('materials')->where('material_code', $code)->exists()) {
                $payload['created_at'] = $now;
            }

            DB::table('materials')->updateOrInsert(['material_code' => $code], $payload);
        }
    }

    private function mergeCharacterMaterials(): void
    {
        if (!Schema::hasTable('character_materials')) {
            return;
        }

        foreach ($this->targetToOldIds() as $targetCode => $oldIds) {
            $targetId = DB::table('materials')->where('material_code', $targetCode)->value('id');
            if (!$targetId || $oldIds->isEmpty()) {
                continue;
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
    }

    private function mergeMaterialDrops(): void
    {
        if (!Schema::hasTable('material_drops')) {
            return;
        }

        foreach ($this->targetToOldIds() as $targetCode => $oldIds) {
            $targetId = DB::table('materials')->where('material_code', $targetCode)->value('id');
            if (!$targetId || $oldIds->isEmpty()) {
                continue;
            }

            foreach (DB::table('material_drops')->whereIn('material_id', $oldIds)->get() as $drop) {
                $existing = DB::table('material_drops')
                    ->where('enemy_id', $drop->enemy_id)
                    ->where('material_id', $targetId)
                    ->first();

                if ($existing) {
                    DB::table('material_drops')
                        ->where('id', $existing->id)
                        ->update([
                            'drop_rate' => max((float) $existing->drop_rate, (float) $drop->drop_rate),
                            'drop_first_clear_only' => (bool) $existing->drop_first_clear_only || (bool) $drop->drop_first_clear_only,
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
    }

    private function replaceRecipeIngredients(): void
    {
        $now = now();
        $map = $this->expandedCodeMap();

        foreach ($map as $oldCode => $targetCode) {
            $oldCode = (string) $oldCode;
            $targetName = self::FRAGMENTS[$targetCode]['name'];

            if (Schema::hasTable('weapon_evolution_recipe_ingredients')) {
                DB::table('weapon_evolution_recipe_ingredients')
                    ->where('ingredient_id', $oldCode)
                    ->update([
                        'ingredient_id' => $targetCode,
                        'ingredient_name' => $targetName,
                        'updated_at' => $now,
                    ]);
            }

            if (Schema::hasTable('armor_evolution_recipe_ingredients')) {
                DB::table('armor_evolution_recipe_ingredients')
                    ->where('material_id', $oldCode)
                    ->update([
                        'material_id' => $targetCode,
                        'material_name' => $targetName,
                        'updated_at' => $now,
                    ]);
            }

            if (Schema::hasTable('accessory_evolution_recipe_ingredients')) {
                DB::table('accessory_evolution_recipe_ingredients')
                    ->where('material_code', $oldCode)
                    ->update([
                        'material_code' => $targetCode,
                        'material_name' => $targetName,
                        'updated_at' => $now,
                    ]);
            }
        }

        if (Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            DB::table('weapon_evolution_recipe_ingredients')
                ->where('ingredient_id', 'TOKEN_CITY_MATERIAL')
                ->update(['ingredient_id' => 'MAT_FINE_EQUIPMENT_FRAGMENT', 'ingredient_name' => '上質な装備の欠片', 'updated_at' => $now]);
            DB::table('weapon_evolution_recipe_ingredients')
                ->where('ingredient_id', 'TOKEN_CITY_HIGH_MATERIAL')
                ->update(['ingredient_id' => 'MAT_STRONG_EQUIPMENT_FRAGMENT', 'ingredient_name' => '強装備の欠片', 'updated_at' => $now]);
        }

        if (Schema::hasTable('armor_evolution_recipe_ingredients')) {
            DB::table('armor_evolution_recipe_ingredients')
                ->where('material_id', '5051')
                ->update(['material_id' => 'MAT_FINE_EQUIPMENT_FRAGMENT', 'material_name' => '上質な装備の欠片', 'updated_at' => $now]);
            DB::table('armor_evolution_recipe_ingredients')
                ->whereIn('material_id', ['5052', '5053', '5054'])
                ->update(['material_id' => 'MAT_STRONG_EQUIPMENT_FRAGMENT', 'material_name' => '強装備の欠片', 'updated_at' => $now]);
        }

        if (Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            DB::table('accessory_evolution_recipe_ingredients')
                ->where('material_code', 'ACC_CITY_MATERIAL')
                ->update(['material_code' => 'MAT_FINE_EQUIPMENT_FRAGMENT', 'material_name' => '上質な装備の欠片', 'updated_at' => $now]);
            DB::table('accessory_evolution_recipe_ingredients')
                ->where('material_code', 'ACC_CITY_HIGH_MATERIAL')
                ->update(['material_code' => 'MAT_STRONG_EQUIPMENT_FRAGMENT', 'material_name' => '強装備の欠片', 'updated_at' => $now]);
        }
    }

    private function replaceEnhancementRecipeText(): void
    {
        if (!Schema::hasTable('weapon_enhancement_recipes')) {
            return;
        }

        foreach ($this->expandedCodeMap() as $oldCode => $targetCode) {
            DB::table('weapon_enhancement_recipes')
                ->where('materials', 'like', '%' . $oldCode . '%')
                ->update([
                    'materials' => DB::raw("REPLACE(materials, " . DB::getPdo()->quote($oldCode) . ", " . DB::getPdo()->quote($targetCode) . ")"),
                    'updated_at' => now(),
                ]);
        }
    }

    private function targetToOldIds(): array
    {
        $result = [];
        foreach ($this->expandedCodeMap() as $oldCode => $targetCode) {
            $oldCode = (string) $oldCode;
            $targetId = DB::table('materials')->where('material_code', $targetCode)->value('id');
            $oldId = DB::table('materials')->where('material_code', $oldCode)->value('id');
            if (!$oldId || (int) $oldId === (int) $targetId) {
                continue;
            }

            $result[$targetCode] ??= collect();
            $result[$targetCode]->push($oldId);
        }

        return $result;
    }

    private function expandedCodeMap(): array
    {
        $map = self::CODE_MAP;

        foreach (range(8, 22) as $number) {
            $map['WEV' . str_pad((string) $number, 4, '0', STR_PAD_LEFT)] = 'MAT_STRONG_EQUIPMENT_FRAGMENT';
        }
        foreach (range(5010, 5024) as $number) {
            $map[(string) $number] = 'MAT_STRONG_EQUIPMENT_FRAGMENT';
        }
        foreach (range(10, 39) as $number) {
            $map['ACC' . str_pad((string) $number, 4, '0', STR_PAD_LEFT)] = 'MAT_STRONG_EQUIPMENT_FRAGMENT';
        }

        return $map;
    }
};
