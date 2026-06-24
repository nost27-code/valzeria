<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NEW_MATERIALS = [
        'MAT_BR_ARM_HEAVY_ARCANE_PATH' => [
            'name' => '重魔装の導石',
            'source' => 'MAT_BR_ARM_HEAVY_PATH',
            'obtain_method' => '重装・魔装系のAランク装備をSランクへ進化させる導石。余剰都市素材の錬成や分岐素材ドロップで入手。',
        ],
        'MAT_BR_ARM_LIGHT_TRAVELER_PATH' => [
            'name' => '軽旅装の導石',
            'source' => 'MAT_BR_ARM_LIGHT_PATH',
            'obtain_method' => '軽装・旅装系のAランク装備をSランクへ進化させる導石。余剰都市素材の錬成や分岐素材ドロップで入手。',
        ],
        'MAT_BR_ACC_POWER_GUARD_PATH' => [
            'name' => '剛守の導石',
            'source' => 'MAT_BR_ACC_POWER_PATH',
            'obtain_method' => '腕力・守護系のAランク装飾品をSランクへ進化させる導石。余剰都市素材の錬成や分岐素材ドロップで入手。',
        ],
        'MAT_BR_ACC_MAGIC_PRAYER_PATH' => [
            'name' => '魔祈の導石',
            'source' => 'MAT_BR_ACC_MAGIC_PATH',
            'obtain_method' => '魔力・祈祷系のAランク装飾品をSランクへ進化させる導石。余剰都市素材の錬成や分岐素材ドロップで入手。',
        ],
        'MAT_BR_ACC_WIND_LUCK_PATH' => [
            'name' => '風運の導石',
            'source' => 'MAT_BR_ACC_WIND_PATH',
            'obtain_method' => '疾風・幸運系のAランク装飾品をSランクへ進化させる導石。余剰都市素材の錬成や分岐素材ドロップで入手。',
        ],
    ];

    private const CODE_MAP = [
        'MAT_BR_ARM_HEAVY_PATH' => 'MAT_BR_ARM_HEAVY_ARCANE_PATH',
        'MAT_BR_ARM_ARCANE_PATH' => 'MAT_BR_ARM_HEAVY_ARCANE_PATH',
        'MAT_BR_ARM_LIGHT_PATH' => 'MAT_BR_ARM_LIGHT_TRAVELER_PATH',
        'MAT_BR_ARM_TRAVELER_PATH' => 'MAT_BR_ARM_LIGHT_TRAVELER_PATH',
        'MAT_BR_ACC_POWER_PATH' => 'MAT_BR_ACC_POWER_GUARD_PATH',
        'MAT_BR_ACC_GUARD_PATH' => 'MAT_BR_ACC_POWER_GUARD_PATH',
        'MAT_BR_ACC_MAGIC_PATH' => 'MAT_BR_ACC_MAGIC_PRAYER_PATH',
        'MAT_BR_ACC_PRAYER_PATH' => 'MAT_BR_ACC_MAGIC_PRAYER_PATH',
        'MAT_BR_ACC_WIND_PATH' => 'MAT_BR_ACC_WIND_LUCK_PATH',
        'MAT_BR_ACC_LUCK_PATH' => 'MAT_BR_ACC_WIND_LUCK_PATH',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $this->upsertNewMaterials();
        $this->mergeOwnedMaterials();
        $this->replaceRecipeIngredients();
        $this->mergeMaterialDrops();
    }

    public function down(): void
    {
        // Master-data consolidation is intentionally not split back because player-owned
        // quantities and merged drop rows cannot be safely restored to their old families.
    }

    private function upsertNewMaterials(): void
    {
        $now = now();

        foreach (self::NEW_MATERIALS as $code => $definition) {
            $source = DB::table('materials')
                ->where('material_code', $definition['source'])
                ->first();

            $payload = [
                'name' => $definition['name'],
                'category' => $source->category ?? '分岐進化素材',
                'rarity' => $source->rarity ?? 'SR',
                'element' => $source->element ?? null,
                'main_use' => $source->main_use ?? '装備進化',
                'npc_sale_price' => $source->npc_sale_price ?? 0,
                'is_tradable' => $source->is_tradable ?? false,
                'city_id' => null,
                'dungeon_id' => null,
                'source_enemy_id' => null,
                'updated_at' => $now,
            ];

            foreach ([
                'drop_rate' => $source->drop_rate ?? 0,
                'drop_first_clear_only' => $source->drop_first_clear_only ?? false,
                'drop_timing' => 'branch_path',
                'material_type' => $source->material_type ?? 'branch_evolution',
                'category_id' => $source->category_id ?? null,
                'rank_tier' => $source->rank_tier ?? 3,
                'is_consumable' => $source->is_consumable ?? true,
                'obtain_method' => $definition['obtain_method'],
            ] as $column => $value) {
                if (Schema::hasColumn('materials', $column)) {
                    $payload[$column] = $value;
                }
            }

            if (!DB::table('materials')->where('material_code', $code)->exists()) {
                $payload['created_at'] = $now;
            }

            DB::table('materials')->updateOrInsert(
                ['material_code' => $code],
                $payload
            );
        }
    }

    private function mergeOwnedMaterials(): void
    {
        if (!Schema::hasTable('character_materials')) {
            return;
        }

        $ids = DB::table('materials')
            ->whereIn('material_code', array_values(array_unique(array_merge(array_keys(self::CODE_MAP), array_values(self::CODE_MAP)))))
            ->pluck('id', 'material_code');

        foreach ($this->groupedOldCodes() as $newCode => $oldCodes) {
            $newId = $ids[$newCode] ?? null;
            $oldIds = collect($oldCodes)
                ->map(fn (string $oldCode) => $ids[$oldCode] ?? null)
                ->filter()
                ->values();

            if (!$newId || $oldIds->isEmpty()) {
                continue;
            }

            $ownedRows = DB::table('character_materials')
                ->whereIn('material_id', $oldIds)
                ->select('character_id', DB::raw('SUM(quantity) as total'))
                ->groupBy('character_id')
                ->get();

            foreach ($ownedRows as $row) {
                $existing = DB::table('character_materials')
                    ->where('character_id', $row->character_id)
                    ->where('material_id', $newId)
                    ->first();

                if ($existing) {
                    DB::table('character_materials')
                        ->where('id', $existing->id)
                        ->update([
                            'quantity' => (int) $existing->quantity + (int) $row->total,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('character_materials')->insert([
                        'character_id' => $row->character_id,
                        'material_id' => $newId,
                        'quantity' => (int) $row->total,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('character_materials')
                ->whereIn('material_id', $oldIds)
                ->delete();
        }
    }

    private function replaceRecipeIngredients(): void
    {
        if (Schema::hasTable('armor_evolution_recipe_ingredients')) {
            foreach (self::CODE_MAP as $oldCode => $newCode) {
                if (!str_starts_with($oldCode, 'MAT_BR_ARM_')) {
                    continue;
                }

                $newName = self::NEW_MATERIALS[$newCode]['name'] ?? null;
                if (!$newName) {
                    continue;
                }

                $rows = DB::table('armor_evolution_recipe_ingredients')
                    ->where('material_id', $oldCode)
                    ->get(['id', 'evolution_recipe_id']);

                foreach ($rows as $row) {
                    DB::table('armor_evolution_recipe_ingredients')
                        ->where('id', $row->id)
                        ->update([
                            'ingredient_id' => 'BR_' . $row->evolution_recipe_id . '_' . $newCode,
                            'material_id' => $newCode,
                            'material_name' => $newName,
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        if (Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            foreach (self::CODE_MAP as $oldCode => $newCode) {
                if (!str_starts_with($oldCode, 'MAT_BR_ACC_')) {
                    continue;
                }

                $newName = self::NEW_MATERIALS[$newCode]['name'] ?? null;
                if (!$newName) {
                    continue;
                }

                DB::table('accessory_evolution_recipe_ingredients')
                    ->where('material_code', $oldCode)
                    ->update([
                        'material_code' => $newCode,
                        'material_name' => $newName,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    private function mergeMaterialDrops(): void
    {
        if (!Schema::hasTable('material_drops')) {
            return;
        }

        $ids = DB::table('materials')
            ->whereIn('material_code', array_values(array_unique(array_merge(array_keys(self::CODE_MAP), array_values(self::CODE_MAP)))))
            ->pluck('id', 'material_code');

        foreach ($this->groupedOldCodes() as $newCode => $oldCodes) {
            $newId = $ids[$newCode] ?? null;
            $oldIds = collect($oldCodes)
                ->map(fn (string $oldCode) => $ids[$oldCode] ?? null)
                ->filter()
                ->values();

            if (!$newId || $oldIds->isEmpty()) {
                continue;
            }

            $drops = DB::table('material_drops')
                ->whereIn('material_id', $oldIds)
                ->get();

            foreach ($drops->groupBy('enemy_id') as $enemyId => $enemyDrops) {
                $dropRate = (float) $enemyDrops->max('drop_rate');
                $timing = (string) ($enemyDrops->first()->drop_timing ?? 'branch_path');

                DB::table('material_drops')->updateOrInsert(
                    [
                        'enemy_id' => $enemyId,
                        'material_id' => $newId,
                    ],
                    [
                        'drop_rate' => $dropRate,
                        'drop_first_clear_only' => (bool) $enemyDrops->max('drop_first_clear_only'),
                        'drop_timing' => $timing,
                        'is_active' => (bool) $enemyDrops->max('is_active'),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            DB::table('material_drops')
                ->whereIn('material_id', $oldIds)
                ->delete();
        }
    }

    private function groupedOldCodes(): array
    {
        $groups = [];

        foreach (self::CODE_MAP as $oldCode => $newCode) {
            $groups[$newCode][] = $oldCode;
        }

        return $groups;
    }
};
