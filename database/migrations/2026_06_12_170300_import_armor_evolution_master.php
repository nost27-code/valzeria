<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureColumns();
        $this->ensureTables();
        $data = $this->loadMaster();
        $now = now();

        $this->importRanks($data['rank_master'] ?? [], $now);
        $this->importFamilies($data['armor_family_master'] ?? [], $now);
        $this->importCategories($data['armor_category_master'] ?? [], $now);
        $this->importMaterials($data['material_master'] ?? [], $now);
        $this->importCityPools($data['city_material_pool'] ?? [], $now);
        $this->importArmors($data['armor_master'] ?? [], $now);
        $this->importEvolutionRecipes($data['evolution_recipe_master'] ?? [], $data['evolution_recipe_ingredients'] ?? [], $now);
        $this->importEnhancementRecipes($data['enhancement_recipe_master'] ?? [], $now);
    }

    public function down(): void
    {
        DB::table('items')->where('type', 'armor')->whereNotNull('external_item_id')->update([
            'is_active' => false,
            'is_shop_item' => false,
            'updated_at' => now(),
        ]);

        Schema::dropIfExists('armor_enhancement_recipes');
        Schema::dropIfExists('armor_evolution_recipe_ingredients');
        Schema::dropIfExists('armor_evolution_recipes');
        Schema::dropIfExists('armor_city_material_pools');
        Schema::dropIfExists('armor_category_masters');
        Schema::dropIfExists('armor_families');
        Schema::dropIfExists('armor_ranks');
    }

    private function ensureColumns(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'armor_family_id')) {
                $table->string('armor_family_id')->nullable()->after('armor_role');
            }
            if (!Schema::hasColumn('items', 'armor_family_name')) {
                $table->string('armor_family_name')->nullable()->after('armor_family_id');
            }
            if (!Schema::hasColumn('items', 'armor_category_id')) {
                $table->string('armor_category_id')->nullable()->after('armor_family_name');
            }
            if (!Schema::hasColumn('items', 'armor_category_name')) {
                $table->string('armor_category_name')->nullable()->after('armor_category_id');
            }
            if (!Schema::hasColumn('items', 'armor_rank')) {
                $table->string('armor_rank')->nullable()->after('armor_category_name');
            }
            if (!Schema::hasColumn('items', 'armor_rank_sort')) {
                $table->unsignedSmallInteger('armor_rank_sort')->default(0)->after('armor_rank');
            }
            if (!Schema::hasColumn('items', 'armor_rank_multiplier')) {
                $table->decimal('armor_rank_multiplier', 10, 4)->default(1)->after('armor_rank_sort');
            }
            if (!Schema::hasColumn('items', 'evolution_group_id')) {
                $table->string('evolution_group_id')->nullable()->after('armor_rank_multiplier');
            }
            if (!Schema::hasColumn('items', 'next_armor_external_id')) {
                $table->string('next_armor_external_id')->nullable()->after('evolution_group_id');
            }
        });
    }

    private function ensureTables(): void
    {
        if (Schema::hasTable('armor_ranks')) {
            goto armor_families;
        }
        Schema::create('armor_ranks', function (Blueprint $table) {
            $table->id();
            $table->string('rank')->unique();
            $table->unsignedSmallInteger('rank_sort');
            $table->decimal('rank_multiplier', 10, 4);
            $table->decimal('enhance_plus_1_multiplier', 10, 4);
            $table->decimal('enhance_plus_2_multiplier', 10, 4);
            $table->decimal('enhance_plus_3_multiplier', 10, 4);
            $table->string('drop_policy')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        armor_families:
        if (Schema::hasTable('armor_families')) {
            goto armor_categories;
        }
        Schema::create('armor_families', function (Blueprint $table) {
            $table->id();
            $table->string('armor_family_id')->unique();
            $table->string('armor_family_name');
            $table->string('armor_category_id')->nullable();
            $table->string('armor_category_name')->nullable();
            $table->text('role')->nullable();
            $table->integer('base_hp')->default(0);
            $table->integer('base_mp')->default(0);
            $table->integer('base_atk')->default(0);
            $table->integer('base_def')->default(0);
            $table->integer('base_mag')->default(0);
            $table->integer('base_spr')->default(0);
            $table->integer('base_spd')->default(0);
            $table->integer('base_luk')->default(0);
            $table->text('recommended_jobs')->nullable();
            $table->timestamps();
        });

        armor_categories:
        if (Schema::hasTable('armor_category_masters')) {
            goto armor_city_material_pools;
        }
        Schema::create('armor_category_masters', function (Blueprint $table) {
            $table->id();
            $table->string('armor_category_id')->unique();
            $table->string('armor_category_name');
            $table->string('low_material_id')->nullable();
            $table->string('low_material_name')->nullable();
            $table->string('mid_material_id')->nullable();
            $table->string('mid_material_name')->nullable();
            $table->string('high_material_id')->nullable();
            $table->string('high_material_name')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        armor_city_material_pools:
        if (Schema::hasTable('armor_city_material_pools')) {
            goto armor_evolution_recipes;
        }
        Schema::create('armor_city_material_pools', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('city_id')->unique();
            $table->string('city_name');
            $table->string('city_material_id');
            $table->string('city_material_name');
            $table->string('city_high_material_id')->nullable();
            $table->string('city_high_material_name')->nullable();
            $table->timestamps();
        });

        armor_evolution_recipes:
        if (Schema::hasTable('armor_evolution_recipes')) {
            goto armor_evolution_recipe_ingredients;
        }
        Schema::create('armor_evolution_recipes', function (Blueprint $table) {
            $table->id();
            $table->string('evolution_recipe_id')->unique();
            $table->string('source_armor_id');
            $table->string('source_armor_name');
            $table->string('target_armor_id');
            $table->string('target_armor_name');
            $table->string('armor_family_id')->nullable();
            $table->string('armor_family_name')->nullable();
            $table->string('from_rank')->nullable();
            $table->string('to_rank')->nullable();
            $table->unsignedSmallInteger('required_same_armor_count')->default(0);
            $table->unsignedInteger('required_gold')->default(0);
            $table->unsignedInteger('required_kiseki')->default(0);
            $table->unsignedBigInteger('unlock_city_id')->nullable();
            $table->string('unlock_condition')->nullable();
            $table->unsignedSmallInteger('success_rate')->default(100);
            $table->boolean('is_active')->default(true);
            $table->text('implementation_note')->nullable();
            $table->timestamps();
        });

        armor_evolution_recipe_ingredients:
        if (Schema::hasTable('armor_evolution_recipe_ingredients')) {
            goto armor_enhancement_recipes;
        }
        Schema::create('armor_evolution_recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('ingredient_id')->unique();
            $table->string('evolution_recipe_id')->index();
            $table->string('ingredient_type');
            $table->string('material_id');
            $table->string('material_name');
            $table->unsignedInteger('required_quantity')->default(1);
            $table->string('resolve_rule')->nullable();
            $table->timestamps();
        });

        armor_enhancement_recipes:
        if (Schema::hasTable('armor_enhancement_recipes')) {
            return;
        }
        Schema::create('armor_enhancement_recipes', function (Blueprint $table) {
            $table->id();
            $table->string('enhancement_recipe_id')->unique();
            $table->string('target_equipment_type')->default('armor');
            $table->unsignedSmallInteger('enhancement_level');
            $table->string('required_material_id');
            $table->string('required_material_name');
            $table->unsignedInteger('required_quantity')->default(1);
            $table->unsignedSmallInteger('success_rate')->default(100);
            $table->unsignedInteger('required_gold')->default(0);
            $table->unsignedInteger('required_kiseki')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    private function loadMaster(): array
    {
        $path = database_path('data/armor_evolution_master.json');
        if (!is_file($path)) {
            throw new RuntimeException('armor_evolution_master.json not found.');
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function importRanks(array $rows, $now): void
    {
        foreach ($rows as $row) {
            DB::table('armor_ranks')->updateOrInsert(
                ['rank' => $row['rank']],
                [
                    'rank_sort' => (int) $row['rank_sort'],
                    'rank_multiplier' => (float) $row['rank_multiplier'],
                    'enhance_plus_1_multiplier' => (float) $row['enhance_plus_1_multiplier'],
                    'enhance_plus_2_multiplier' => (float) $row['enhance_plus_2_multiplier'],
                    'enhance_plus_3_multiplier' => (float) $row['enhance_plus_3_multiplier'],
                    'drop_policy' => $row['drop_policy'] ?? null,
                    'note' => $row['note'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function importFamilies(array $rows, $now): void
    {
        foreach ($rows as $row) {
            DB::table('armor_families')->updateOrInsert(
                ['armor_family_id' => $row['armor_family_id']],
                [
                    'armor_family_name' => $row['armor_family_name'],
                    'armor_category_id' => $row['armor_category_id'] ?? null,
                    'armor_category_name' => $row['armor_category_name'] ?? null,
                    'role' => $row['role'] ?? null,
                    'base_hp' => (int) ($row['base_HP'] ?? 0),
                    'base_mp' => (int) ($row['base_MP'] ?? 0),
                    'base_atk' => (int) ($row['base_ATK'] ?? 0),
                    'base_def' => (int) ($row['base_DEF'] ?? 0),
                    'base_mag' => (int) ($row['base_MAG'] ?? 0),
                    'base_spr' => (int) ($row['base_SPR'] ?? 0),
                    'base_spd' => (int) ($row['base_SPD'] ?? 0),
                    'base_luk' => (int) ($row['base_LUK'] ?? 0),
                    'recommended_jobs' => $row['recommended_jobs'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function importCategories(array $rows, $now): void
    {
        foreach ($rows as $row) {
            DB::table('armor_category_masters')->updateOrInsert(
                ['armor_category_id' => $row['armor_category_id']],
                [
                    'armor_category_name' => $row['armor_category_name'],
                    'low_material_id' => $row['low_material_id'] ?? null,
                    'low_material_name' => $row['low_material_name'] ?? null,
                    'mid_material_id' => $row['mid_material_id'] ?? null,
                    'mid_material_name' => $row['mid_material_name'] ?? null,
                    'high_material_id' => $row['high_material_id'] ?? null,
                    'high_material_name' => $row['high_material_name'] ?? null,
                    'note' => $row['note'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function importMaterials(array $rows, $now): void
    {
        foreach ($rows as $row) {
            DB::table('materials')->updateOrInsert(
                ['material_code' => (string) $row['material_id']],
                [
                    'name' => $row['material_name'],
                    'material_type' => $row['material_category'] ?? null,
                    'category_id' => null,
                    'rank_tier' => $this->gradeTier($row['material_grade'] ?? null),
                    'is_consumable' => true,
                    'obtain_method' => $row['primary_source'] ?? null,
                    'category' => $row['material_category'] ?? 'armor_evolution',
                    'rarity' => strtoupper((string) ($row['material_grade'] ?? 'low')),
                    'element' => null,
                    'main_use' => '防具進化・強化',
                    'npc_sale_price' => 0,
                    'is_tradable' => (bool) ($row['is_tradeable'] ?? false),
                    'city_id' => null,
                    'dungeon_id' => null,
                    'source_enemy_id' => null,
                    'drop_rate' => 0,
                    'drop_first_clear_only' => false,
                    'drop_timing' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function importCityPools(array $rows, $now): void
    {
        foreach ($rows as $row) {
            DB::table('armor_city_material_pools')->updateOrInsert(
                ['city_id' => (int) $row['city_id']],
                [
                    'city_name' => $row['city_name'],
                    'city_material_id' => (string) $row['city_material_id'],
                    'city_material_name' => $row['city_material_name'],
                    'city_high_material_id' => isset($row['city_high_material_id']) ? (string) $row['city_high_material_id'] : null,
                    'city_high_material_name' => $row['city_high_material_name'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function importArmors(array $rows, $now): void
    {
        $armorIds = array_map('strval', array_values(array_filter(array_column($rows, 'armor_id'))));
        DB::table('items')->where('type', 'armor')->where(function ($query) use ($armorIds) {
            $query->whereNull('external_item_id');
            if (!empty($armorIds)) {
                $query->orWhereNotIn('external_item_id', $armorIds);
            }
        })->update([
            'is_active' => false,
            'is_shop_item' => false,
            'updated_at' => $now,
        ]);

        foreach ($rows as $row) {
            $externalId = (string) $row['armor_id'];
            $existing = DB::table('items')
                ->where('external_item_id', $externalId)
                ->orWhere(function ($query) use ($row) {
                    $query->where('type', 'armor')->where('name', $row['armor_name']);
                })
                ->first();

            $payload = [
                'external_item_id' => $externalId,
                'name' => $row['armor_name'],
                'type' => 'armor',
                'description' => $row['description'] ?? null,
                'rarity' => (string) $row['rank'],
                'price' => 0,
                'sell_price' => 0,
                'hp_bonus' => (int) ($row['HP'] ?? 0),
                'mp_bonus' => (int) ($row['MP'] ?? 0),
                'str_bonus' => (int) ($row['ATK'] ?? 0),
                'def_bonus' => (int) ($row['DEF'] ?? 0),
                'agi_bonus' => (int) ($row['SPD'] ?? 0),
                'mag_bonus' => (int) ($row['MAG'] ?? 0),
                'spr_bonus' => (int) ($row['SPR'] ?? 0),
                'luk_bonus' => (int) ($row['LUK'] ?? 0),
                'required_level' => 1,
                'is_shop_item' => false,
                'is_active' => true,
                'sort_order' => (int) ($row['rank_sort'] ?? 0) * 100 + (int) ($row['evolution_stage'] ?? 0),
                'unlock_city_id' => null,
                'sub_type' => $row['armor_family_name'] ?? null,
                'element' => null,
                'armor_category' => $this->equipmentArmorCategory($row['armor_family_id'] ?? null),
                'armor_family_id' => $row['armor_family_id'] ?? null,
                'armor_family_name' => $row['armor_family_name'] ?? null,
                'armor_category_id' => $row['armor_category_id'] ?? null,
                'armor_category_name' => $row['armor_category_name'] ?? null,
                'armor_rank' => $row['rank'] ?? null,
                'armor_rank_sort' => (int) ($row['rank_sort'] ?? 0),
                'armor_rank_multiplier' => (float) ($row['rank_multiplier'] ?? 1),
                'evolution_group_id' => $row['evolution_group_id'] ?? null,
                'evolution_stage' => (int) ($row['evolution_stage'] ?? 0),
                'next_armor_external_id' => isset($row['next_armor_id']) ? (string) $row['next_armor_id'] : null,
                'next_item_external_id' => isset($row['next_armor_id']) ? (string) $row['next_armor_id'] : null,
                'is_drop_enabled' => (bool) ($row['is_drop_enabled'] ?? false),
                'is_supply_enabled' => (bool) ($row['is_shop_enabled'] ?? false),
                'is_evolution_enabled' => (bool) ($row['is_evolution_enabled'] ?? false),
                'max_enhance' => (int) ($row['max_enhance'] ?? 0),
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('items')->where('id', $existing->id)->update($payload);
            } else {
                $payload['created_at'] = $now;
                DB::table('items')->insert($payload);
            }
        }
    }

    private function importEvolutionRecipes(array $recipes, array $ingredients, $now): void
    {
        DB::table('armor_evolution_recipe_ingredients')->delete();

        foreach ($recipes as $row) {
            DB::table('armor_evolution_recipes')->updateOrInsert(
                ['evolution_recipe_id' => (string) $row['evolution_recipe_id']],
                [
                    'source_armor_id' => (string) $row['source_armor_id'],
                    'source_armor_name' => $row['source_armor_name'],
                    'target_armor_id' => (string) $row['target_armor_id'],
                    'target_armor_name' => $row['target_armor_name'],
                    'armor_family_id' => $row['armor_family_id'] ?? null,
                    'armor_family_name' => $row['armor_family_name'] ?? null,
                    'from_rank' => $row['from_rank'] ?? null,
                    'to_rank' => $row['to_rank'] ?? null,
                    'required_same_armor_count' => (int) ($row['required_same_armor_count'] ?? 0),
                    'required_gold' => (int) ($row['required_gold'] ?? 0),
                    'required_kiseki' => (int) ($row['required_kiseki'] ?? 0),
                    'unlock_city_id' => $row['unlock_city_id'] ?? null,
                    'unlock_condition' => $row['unlock_condition'] ?? null,
                    'success_rate' => (int) ($row['success_rate'] ?? 100),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'implementation_note' => $row['implementation_note'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        foreach ($ingredients as $row) {
            DB::table('armor_evolution_recipe_ingredients')->insert([
                'ingredient_id' => (string) $row['ingredient_id'],
                'evolution_recipe_id' => (string) $row['evolution_recipe_id'],
                'ingredient_type' => $row['ingredient_type'],
                'material_id' => (string) $row['material_id'],
                'material_name' => $row['material_name'],
                'required_quantity' => (int) ($row['required_quantity'] ?? 1),
                'resolve_rule' => $row['resolve_rule'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function equipmentArmorCategory(?string $familyId): ?string
    {
        return match ($familyId) {
            'light_armor' => 'light_armor',
            'heavy_armor' => 'heavy_armor',
            'robe', 'arcane_armor', 'holy_vestment' => 'robe',
            'traveler_wear', 'martial_garb' => 'clothes',
            'shadow_garb' => 'cloak',
            default => null,
        };
    }

    private function importEnhancementRecipes(array $rows, $now): void
    {
        foreach ($rows as $row) {
            DB::table('armor_enhancement_recipes')->updateOrInsert(
                ['enhancement_recipe_id' => (string) $row['enhancement_recipe_id']],
                [
                    'target_equipment_type' => $row['target_equipment_type'] ?? 'armor',
                    'enhancement_level' => (int) $row['enhancement_level'],
                    'required_material_id' => (string) $row['required_material_id'],
                    'required_material_name' => $row['required_material_name'],
                    'required_quantity' => (int) ($row['required_quantity'] ?? 1),
                    'success_rate' => (int) ($row['success_rate'] ?? 100),
                    'required_gold' => (int) ($row['required_gold'] ?? 0),
                    'required_kiseki' => (int) ($row['required_kiseki'] ?? 0),
                    'note' => $row['note'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function gradeTier(mixed $grade): int
    {
        return match ((string) $grade) {
            'mid' => 2,
            'high' => 3,
            'city' => 4,
            'rare' => 5,
            default => 1,
        };
    }
};
