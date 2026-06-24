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
        $this->ensureMasterTables();
        $data = $this->loadMaster();
        $now = now();

        $this->importRanks($data['rank_master'] ?? [], $now);
        $this->importWeaponFamilies($data['weapon_family_master'] ?? [], $now);
        $this->importMaterials($data['material_master'] ?? [], $now);
        $this->importCityMaterialPools($data['city_material_pool'] ?? [], $now);
        $this->importWeapons($data['weapon_master'] ?? [], $now);
        $this->importEvolutionRecipes($data['evolution_recipe_master'] ?? [], $data['evolution_recipe_ingredients'] ?? [], $now);
        $this->importEnhancementRecipes($data['enhancement_recipe_master'] ?? [], $now);
    }

    public function down(): void
    {
        DB::table('items')->whereNotNull('external_item_id')->where('external_item_id', 'like', 'WPN\_%')->update([
            'is_active' => false,
            'is_shop_item' => false,
            'updated_at' => now(),
        ]);

        Schema::dropIfExists('weapon_enhancement_recipes');
        Schema::dropIfExists('weapon_evolution_recipe_ingredients');
        Schema::dropIfExists('weapon_evolution_recipes');
        Schema::dropIfExists('city_material_pools');
        Schema::dropIfExists('weapon_families');
        Schema::dropIfExists('weapon_ranks');
    }

    private function ensureColumns(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'external_item_id')) {
                $table->string('external_item_id')->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('items', 'weapon_family_id')) {
                $table->string('weapon_family_id')->nullable()->after('weapon_category');
            }
            if (!Schema::hasColumn('items', 'weapon_family_name')) {
                $table->string('weapon_family_name')->nullable()->after('weapon_family_id');
            }
            if (!Schema::hasColumn('items', 'weapon_rank')) {
                $table->string('weapon_rank')->nullable()->after('weapon_family_name');
            }
            if (!Schema::hasColumn('items', 'weapon_rank_sort')) {
                $table->unsignedSmallInteger('weapon_rank_sort')->default(0)->after('weapon_rank');
            }
            if (!Schema::hasColumn('items', 'weapon_rank_multiplier')) {
                $table->decimal('weapon_rank_multiplier', 10, 4)->default(1)->after('weapon_rank_sort');
            }
            if (!Schema::hasColumn('items', 'evolution_stage')) {
                $table->unsignedSmallInteger('evolution_stage')->default(0)->after('weapon_rank_multiplier');
            }
            if (!Schema::hasColumn('items', 'next_item_external_id')) {
                $table->string('next_item_external_id')->nullable()->after('evolution_stage');
            }
            if (!Schema::hasColumn('items', 'is_evolution_enabled')) {
                $table->boolean('is_evolution_enabled')->default(false)->after('next_item_external_id');
            }
            if (!Schema::hasColumn('items', 'is_drop_enabled')) {
                $table->boolean('is_drop_enabled')->default(false)->after('is_evolution_enabled');
            }
            if (!Schema::hasColumn('items', 'is_supply_enabled')) {
                $table->boolean('is_supply_enabled')->default(false)->after('is_drop_enabled');
            }
            if (!Schema::hasColumn('items', 'max_enhance')) {
                $table->unsignedSmallInteger('max_enhance')->default(0)->after('is_supply_enabled');
            }
        });

        Schema::table('materials', function (Blueprint $table) {
            if (!Schema::hasColumn('materials', 'material_type')) {
                $table->string('material_type')->nullable()->after('name');
            }
            if (!Schema::hasColumn('materials', 'category_id')) {
                $table->string('category_id')->nullable()->after('material_type');
            }
            if (!Schema::hasColumn('materials', 'rank_tier')) {
                $table->unsignedSmallInteger('rank_tier')->default(1)->after('category_id');
            }
            if (!Schema::hasColumn('materials', 'is_consumable')) {
                $table->boolean('is_consumable')->default(true)->after('rank_tier');
            }
            if (!Schema::hasColumn('materials', 'obtain_method')) {
                $table->string('obtain_method')->nullable()->after('is_consumable');
            }
        });
    }

    private function ensureMasterTables(): void
    {
        Schema::create('weapon_ranks', function (Blueprint $table) {
            $table->id();
            $table->string('rank')->unique();
            $table->unsignedSmallInteger('rank_sort');
            $table->decimal('multiplier', 10, 4);
            $table->unsignedSmallInteger('enhance_max')->default(3);
            $table->decimal('enhance_plus1_rate', 5, 4)->default(0.03);
            $table->text('rule_note')->nullable();
            $table->timestamps();
        });

        Schema::create('weapon_families', function (Blueprint $table) {
            $table->id();
            $table->string('weapon_family_id')->unique();
            $table->string('weapon_family_name');
            $table->string('category_id')->nullable();
            $table->string('category_name')->nullable();
            $table->integer('base_hp')->default(0);
            $table->integer('base_mp')->default(0);
            $table->integer('base_atk')->default(0);
            $table->integer('base_def')->default(0);
            $table->integer('base_mag')->default(0);
            $table->integer('base_spr')->default(0);
            $table->integer('base_spd')->default(0);
            $table->integer('base_luk')->default(0);
            $table->text('trait')->nullable();
            $table->timestamps();
        });

        Schema::create('city_material_pools', function (Blueprint $table) {
            $table->id();
            $table->string('material_id')->unique();
            $table->string('material_name');
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('city_name')->nullable();
            $table->string('material_group')->nullable();
            $table->unsignedSmallInteger('rank_tier')->default(1);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('weapon_evolution_recipes', function (Blueprint $table) {
            $table->id();
            $table->string('recipe_id')->unique();
            $table->string('from_weapon_id');
            $table->string('from_weapon_name');
            $table->string('to_weapon_id');
            $table->string('to_weapon_name');
            $table->string('weapon_family_id')->nullable();
            $table->string('category_id')->nullable();
            $table->string('from_rank')->nullable();
            $table->string('to_rank')->nullable();
            $table->unsignedSmallInteger('same_weapon_count')->default(0);
            $table->string('unlock_condition')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('weapon_evolution_recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('recipe_id')->index();
            $table->string('ingredient_type');
            $table->string('ingredient_id');
            $table->string('ingredient_name');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('resolve_rule')->nullable();
            $table->boolean('is_consumed')->default(true);
            $table->timestamps();
        });

        Schema::create('weapon_enhancement_recipes', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('enhance_level')->unique();
            $table->json('materials');
            $table->unsignedSmallInteger('success_rate')->default(100);
            $table->string('effect')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    private function loadMaster(): array
    {
        $path = database_path('data/weapon_evolution_master.json');
        if (!is_file($path)) {
            throw new RuntimeException('weapon_evolution_master.json not found.');
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function importRanks(array $rows, $now): void
    {
        foreach ($rows as $row) {
            DB::table('weapon_ranks')->updateOrInsert(
                ['rank' => $row['rank']],
                [
                    'rank_sort' => (int) $row['rank_sort'],
                    'multiplier' => (float) $row['multiplier'],
                    'enhance_max' => (int) $row['enhance_max'],
                    'enhance_plus1_rate' => (float) $row['enhance_plus1_rate'],
                    'rule_note' => $row['rule_note'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function importWeaponFamilies(array $rows, $now): void
    {
        foreach ($rows as $row) {
            DB::table('weapon_families')->updateOrInsert(
                ['weapon_family_id' => $row['weapon_family_id']],
                [
                    'weapon_family_name' => $row['weapon_family_name'],
                    'category_id' => $row['category_id'] ?? null,
                    'category_name' => $row['category_name'] ?? null,
                    'base_hp' => (int) ($row['base_hp'] ?? 0),
                    'base_mp' => (int) ($row['base_mp'] ?? 0),
                    'base_atk' => (int) ($row['base_atk'] ?? 0),
                    'base_def' => (int) ($row['base_def'] ?? 0),
                    'base_mag' => (int) ($row['base_mag'] ?? 0),
                    'base_spr' => (int) ($row['base_spr'] ?? 0),
                    'base_spd' => (int) ($row['base_spd'] ?? 0),
                    'base_luk' => (int) ($row['base_luk'] ?? 0),
                    'trait' => $row['trait'] ?? null,
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
                ['material_code' => $row['material_id']],
                [
                    'name' => $row['material_name'],
                    'material_type' => $row['material_type'] ?? null,
                    'category_id' => $row['category_id'] ?? null,
                    'rank_tier' => (int) ($row['rank_tier'] ?? 1),
                    'is_consumable' => (bool) ($row['is_consumable'] ?? true),
                    'obtain_method' => $row['obtain_method'] ?? null,
                    'category' => $row['material_type'] ?? 'weapon_evolution',
                    'rarity' => 'T' . (int) ($row['rank_tier'] ?? 1),
                    'element' => null,
                    'main_use' => '武器進化・強化',
                    'npc_sale_price' => 0,
                    'is_tradable' => true,
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

    private function importCityMaterialPools(array $rows, $now): void
    {
        foreach ($rows as $row) {
            DB::table('city_material_pools')->updateOrInsert(
                ['material_id' => $row['material_id']],
                [
                    'material_name' => $row['material_name'],
                    'city_id' => $row['city_id'] ?? null,
                    'city_name' => $row['city_name'] ?? null,
                    'material_group' => $row['material_group'] ?? null,
                    'rank_tier' => (int) ($row['rank_tier'] ?? 1),
                    'description' => $row['description'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function importWeapons(array $rows, $now): void
    {
        $weaponIds = array_values(array_filter(array_column($rows, 'weapon_id')));
        DB::table('items')->where('type', 'weapon')->where(function ($query) use ($weaponIds) {
            $query->whereNull('external_item_id')->orWhereNotIn('external_item_id', $weaponIds);
        })->update([
            'is_active' => false,
            'is_shop_item' => false,
            'updated_at' => $now,
        ]);

        foreach ($rows as $row) {
            $existing = DB::table('items')
                ->where('external_item_id', $row['weapon_id'])
                ->orWhere(function ($query) use ($row) {
                    $query->where('type', 'weapon')->where('name', $row['weapon_name']);
                })
                ->first();

            $payload = [
                'external_item_id' => $row['weapon_id'],
                'name' => $row['weapon_name'],
                'type' => 'weapon',
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
                'sub_type' => $row['weapon_family_name'] ?? null,
                'element' => null,
                'weapon_category' => $this->equipmentWeaponCategory($row['weapon_family_id'] ?? null),
                'weapon_family_id' => $row['weapon_family_id'] ?? null,
                'weapon_family_name' => $row['weapon_family_name'] ?? null,
                'weapon_rank' => $row['rank'] ?? null,
                'weapon_rank_sort' => (int) ($row['rank_sort'] ?? 0),
                'weapon_rank_multiplier' => (float) ($row['rank_multiplier'] ?? 1),
                'evolution_stage' => (int) ($row['evolution_stage'] ?? 0),
                'next_item_external_id' => $row['next_weapon_id'] ?? null,
                'is_evolution_enabled' => (bool) ($row['is_evolution_enabled'] ?? false),
                'is_drop_enabled' => (bool) ($row['is_drop_enabled'] ?? false),
                'is_supply_enabled' => (bool) ($row['is_supply_enabled'] ?? false),
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
        DB::table('weapon_evolution_recipe_ingredients')->delete();

        foreach ($recipes as $row) {
            DB::table('weapon_evolution_recipes')->updateOrInsert(
                ['recipe_id' => $row['recipe_id']],
                [
                    'from_weapon_id' => $row['from_weapon_id'],
                    'from_weapon_name' => $row['from_weapon_name'],
                    'to_weapon_id' => $row['to_weapon_id'],
                    'to_weapon_name' => $row['to_weapon_name'],
                    'weapon_family_id' => $row['weapon_family_id'] ?? null,
                    'category_id' => $row['category_id'] ?? null,
                    'from_rank' => $row['from_rank'] ?? null,
                    'to_rank' => $row['to_rank'] ?? null,
                    'same_weapon_count' => (int) ($row['same_weapon_count'] ?? 0),
                    'unlock_condition' => $row['unlock_condition'] ?? null,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'note' => $row['note'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        foreach ($ingredients as $row) {
            DB::table('weapon_evolution_recipe_ingredients')->insert([
                'recipe_id' => $row['recipe_id'],
                'ingredient_type' => $row['ingredient_type'],
                'ingredient_id' => $row['ingredient_id'],
                'ingredient_name' => $row['ingredient_name'],
                'quantity' => (int) ($row['quantity'] ?? 1),
                'resolve_rule' => $row['resolve_rule'] ?? null,
                'is_consumed' => (bool) ($row['is_consumed'] ?? true),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function equipmentWeaponCategory(?string $familyId): ?string
    {
        return match ($familyId) {
            'SWORD' => 'sword',
            'DAGGER' => 'dagger',
            'SPEAR' => 'spear',
            'AXE' => 'axe',
            'CLUB' => 'axe',
            'BOW' => 'bow',
            'STAFF' => 'staff',
            'GRIMOIRE' => 'magic_device',
            'FIST' => 'fist',
            'GUN' => 'gun',
            default => null,
        };
    }

    private function importEnhancementRecipes(array $rows, $now): void
    {
        foreach ($rows as $row) {
            $materials = [];
            foreach ([1, 2] as $index) {
                $id = $row["required_material_id_{$index}"] ?? null;
                $quantity = (int) ($row["quantity_{$index}"] ?? 0);
                if ($id && $quantity > 0) {
                    $materials[] = [
                        'material_id' => $id,
                        'material_name' => $row["required_material_name_{$index}"] ?? $id,
                        'quantity' => $quantity,
                    ];
                }
            }

            DB::table('weapon_enhancement_recipes')->updateOrInsert(
                ['enhance_level' => (int) $row['enhance_level']],
                [
                    'materials' => json_encode($materials, JSON_UNESCAPED_UNICODE),
                    'success_rate' => (int) round(((float) ($row['success_rate'] ?? 1)) * 100),
                    'effect' => $row['effect'] ?? null,
                    'note' => $row['note'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
};
