<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('items') && !Schema::hasColumn('items', 'affix_enabled')) {
            Schema::table('items', function (Blueprint $table) {
                $column = $table->boolean('affix_enabled')->default(false);
                if (Schema::hasColumn('items', 'is_drop_enabled')) {
                    $column->after('is_drop_enabled');
                }
            });
        }

        if (Schema::hasTable('enemies') && !Schema::hasColumn('enemies', 'species_key')) {
            Schema::table('enemies', function (Blueprint $table) {
                $table->string('species_key', 32)->nullable()->after('type_name');
            });
        }

        if (!Schema::hasTable('equipment_affix_prefixes')) {
            Schema::create('equipment_affix_prefixes', function (Blueprint $table) {
                $table->id();
                $table->string('affix_key', 32)->unique();
                $table->string('name', 50);
                $table->string('target_stat', 32);
                $table->decimal('calculation_rate', 6, 4)->default(0);
                $table->integer('roll_weight')->default(100);
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('equipment_affix_suffixes')) {
            Schema::create('equipment_affix_suffixes', function (Blueprint $table) {
                $table->id();
                $table->string('species_key', 32)->unique();
                $table->string('name', 50);
                $table->decimal('base_killer_rate', 5, 4)->default(0.05);
                $table->integer('roll_weight')->default(100);
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('character_items')) {
            Schema::table('character_items', function (Blueprint $table) {
                if (!Schema::hasColumn('character_items', 'affix_prefix_id')) {
                    $table->foreignId('affix_prefix_id')->nullable()->after('item_id')->constrained('equipment_affix_prefixes')->nullOnDelete();
                }
                if (!Schema::hasColumn('character_items', 'affix_suffix_id')) {
                    $table->foreignId('affix_suffix_id')->nullable()->after('affix_prefix_id')->constrained('equipment_affix_suffixes')->nullOnDelete();
                }
                if (!Schema::hasColumn('character_items', 'affix_quality')) {
                    $table->string('affix_quality', 20)->nullable()->after('affix_suffix_id');
                }
                if (!Schema::hasColumn('character_items', 'affix_hp_bonus')) {
                    $table->integer('affix_hp_bonus')->default(0)->after('affix_quality');
                }
                if (!Schema::hasColumn('character_items', 'affix_str_bonus')) {
                    $table->integer('affix_str_bonus')->default(0)->after('affix_hp_bonus');
                }
                if (!Schema::hasColumn('character_items', 'affix_def_bonus')) {
                    $table->integer('affix_def_bonus')->default(0)->after('affix_str_bonus');
                }
                if (!Schema::hasColumn('character_items', 'affix_mag_bonus')) {
                    $table->integer('affix_mag_bonus')->default(0)->after('affix_def_bonus');
                }
                if (!Schema::hasColumn('character_items', 'affix_spr_bonus')) {
                    $table->integer('affix_spr_bonus')->default(0)->after('affix_mag_bonus');
                }
                if (!Schema::hasColumn('character_items', 'affix_agi_bonus')) {
                    $table->integer('affix_agi_bonus')->default(0)->after('affix_spr_bonus');
                }
                if (!Schema::hasColumn('character_items', 'affix_luk_bonus')) {
                    $table->integer('affix_luk_bonus')->default(0)->after('affix_agi_bonus');
                }
                if (!Schema::hasColumn('character_items', 'killer_species_key')) {
                    $table->string('killer_species_key', 32)->nullable()->after('affix_luk_bonus');
                }
                if (!Schema::hasColumn('character_items', 'killer_damage_rate')) {
                    $table->decimal('killer_damage_rate', 5, 4)->default(0)->after('killer_species_key');
                }
                if (!Schema::hasColumn('character_items', 'affix_generated_at')) {
                    $table->timestamp('affix_generated_at')->nullable()->after('killer_damage_rate');
                }
            });
        }

        $this->seedAffixMasters();
        $this->syncEnemySpeciesKeys();
        $this->enableAffixesForDropWeapons();
    }

    public function down(): void
    {
        if (Schema::hasTable('character_items')) {
            Schema::table('character_items', function (Blueprint $table) {
                foreach ([
                    'affix_prefix_id',
                    'affix_suffix_id',
                    'affix_quality',
                    'affix_hp_bonus',
                    'affix_str_bonus',
                    'affix_def_bonus',
                    'affix_mag_bonus',
                    'affix_spr_bonus',
                    'affix_agi_bonus',
                    'affix_luk_bonus',
                    'killer_species_key',
                    'killer_damage_rate',
                    'affix_generated_at',
                ] as $column) {
                    if (Schema::hasColumn('character_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('equipment_affix_suffixes');
        Schema::dropIfExists('equipment_affix_prefixes');

        if (Schema::hasTable('enemies') && Schema::hasColumn('enemies', 'species_key')) {
            Schema::table('enemies', fn (Blueprint $table) => $table->dropColumn('species_key'));
        }

        if (Schema::hasTable('items') && Schema::hasColumn('items', 'affix_enabled')) {
            Schema::table('items', fn (Blueprint $table) => $table->dropColumn('affix_enabled'));
        }
    }

    private function seedAffixMasters(): void
    {
        $now = now();
        $prefixes = [
            ['life', '生命の', 'hp', 0.3500, 100, 10],
            ['power', '剛力の', 'str', 0.0700, 100, 20],
            ['sturdy', '堅牢の', 'def', 0.0700, 100, 30],
            ['arcane', '魔導の', 'mag', 0.0700, 100, 40],
            ['prayer', '祈祷の', 'spr', 0.0700, 100, 50],
            ['gale', '疾風の', 'agi', 0.0700, 100, 60],
            ['fortune', '豪運の', 'luk', 0.0800, 100, 70],
            ['tuning', '調律の', 'all', 0.0200, 80, 80],
        ];

        foreach ($prefixes as [$key, $name, $stat, $rate, $weight, $order]) {
            DB::table('equipment_affix_prefixes')->updateOrInsert(
                ['affix_key' => $key],
                [
                    'name' => $name,
                    'target_stat' => $stat,
                    'calculation_rate' => $rate,
                    'roll_weight' => $weight,
                    'is_active' => true,
                    'sort_order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $suffixes = [
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

        foreach ($suffixes as [$key, $name, $order]) {
            DB::table('equipment_affix_suffixes')->updateOrInsert(
                ['species_key' => $key],
                [
                    'name' => $name,
                    'base_killer_rate' => 0.0500,
                    'roll_weight' => 100,
                    'is_active' => true,
                    'sort_order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function syncEnemySpeciesKeys(): void
    {
        if (!Schema::hasTable('enemies') || !Schema::hasColumn('enemies', 'species_key')) {
            return;
        }

        if (Schema::hasColumn('enemies', 'family_key')) {
            DB::table('enemies')
                ->whereNull('species_key')
                ->whereNotNull('family_key')
                ->update(['species_key' => DB::raw('family_key')]);
        }
    }

    private function enableAffixesForDropWeapons(): void
    {
        if (!Schema::hasTable('items') || !Schema::hasColumn('items', 'affix_enabled')) {
            return;
        }

        DB::table('items')->update(['affix_enabled' => false]);

        $query = DB::table('items')
            ->where('type', 'weapon')
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('is_shop_item')
                    ->orWhere('is_shop_item', false);
            })
            ->whereNotIn('rarity', ['EPIC', 'epic', 'LEGEND', 'legend']);

        if (Schema::hasColumn('items', 'external_item_id')) {
            $query->where(function ($query) {
                $query->where('external_item_id', 'like', 'DROP_WPN_%')
                    ->orWhere(function ($query) {
                        $query->where('is_drop_enabled', true)
                            ->where('external_item_id', 'not like', 'SHOP_WPN_%')
                            ->where('external_item_id', 'not like', 'BR_%');
                    });
            });
        } elseif (Schema::hasColumn('items', 'is_drop_enabled')) {
            $query->where('is_drop_enabled', true);
        }

        $query->update(['affix_enabled' => true]);
    }
};
