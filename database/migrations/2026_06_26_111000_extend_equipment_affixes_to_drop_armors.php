<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendSuffixes();
        $this->extendCharacterItems();
        $this->seedArmorSuffixes();
        $this->enableAffixesForDropArmors();
    }

    public function down(): void
    {
        // No rollback: existing affixed equipment may reference the added armor suffix rows.
    }

    private function extendSuffixes(): void
    {
        if (!Schema::hasTable('equipment_affix_suffixes')) {
            Schema::create('equipment_affix_suffixes', function (Blueprint $table) {
                $table->id();
                $table->string('item_type', 20)->default('weapon');
                $table->string('effect_type', 32)->default('killer_damage');
                $table->string('species_key', 32);
                $table->string('name', 50);
                $table->decimal('base_killer_rate', 5, 4)->default(0.05);
                $table->decimal('base_effect_rate', 5, 4)->default(0.05);
                $table->integer('roll_weight')->default(100);
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['item_type', 'effect_type', 'species_key'], 'equipment_affix_suffixes_unique_effect');
            });

            return;
        }

        Schema::table('equipment_affix_suffixes', function (Blueprint $table) {
            if (!Schema::hasColumn('equipment_affix_suffixes', 'item_type')) {
                $table->string('item_type', 20)->default('weapon')->after('id');
            }
            if (!Schema::hasColumn('equipment_affix_suffixes', 'effect_type')) {
                $table->string('effect_type', 32)->default('killer_damage')->after('item_type');
            }
            if (!Schema::hasColumn('equipment_affix_suffixes', 'base_effect_rate')) {
                $table->decimal('base_effect_rate', 5, 4)->default(0.05)->after('base_killer_rate');
            }
        });

        DB::table('equipment_affix_suffixes')
            ->whereNull('item_type')
            ->orWhere('item_type', '')
            ->update(['item_type' => 'weapon']);

        DB::table('equipment_affix_suffixes')
            ->whereNull('effect_type')
            ->orWhere('effect_type', '')
            ->update(['effect_type' => 'killer_damage']);

        DB::table('equipment_affix_suffixes')
            ->where('effect_type', 'killer_damage')
            ->where(function ($query) {
                $query->whereNull('base_effect_rate')->orWhere('base_effect_rate', 0);
            })
            ->update(['base_effect_rate' => DB::raw('base_killer_rate')]);

        try {
            Schema::table('equipment_affix_suffixes', function (Blueprint $table) {
                $table->dropUnique('equipment_affix_suffixes_species_key_unique');
            });
        } catch (\Throwable $e) {
            // Some databases/environments may not have the old single-column unique index.
        }

        try {
            Schema::table('equipment_affix_suffixes', function (Blueprint $table) {
                $table->unique(['item_type', 'effect_type', 'species_key'], 'equipment_affix_suffixes_unique_effect');
            });
        } catch (\Throwable $e) {
            // Index may already exist.
        }
    }

    private function extendCharacterItems(): void
    {
        if (!Schema::hasTable('character_items')) {
            return;
        }

        Schema::table('character_items', function (Blueprint $table) {
            if (!Schema::hasColumn('character_items', 'resist_species_key')) {
                $table->string('resist_species_key', 32)->nullable()->after('killer_damage_rate');
            }
            if (!Schema::hasColumn('character_items', 'species_damage_reduction_rate')) {
                $table->decimal('species_damage_reduction_rate', 5, 4)->default(0)->after('resist_species_key');
            }
        });
    }

    private function seedArmorSuffixes(): void
    {
        if (!Schema::hasTable('equipment_affix_suffixes')) {
            return;
        }

        $now = now();
        $rows = [
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

        foreach ($rows as [$speciesKey, $name, $order]) {
            DB::table('equipment_affix_suffixes')->updateOrInsert(
                [
                    'item_type' => 'armor',
                    'effect_type' => 'species_resist',
                    'species_key' => $speciesKey,
                ],
                [
                    'name' => $name,
                    'base_killer_rate' => 0,
                    'base_effect_rate' => 0.0400,
                    'roll_weight' => 100,
                    'is_active' => true,
                    'sort_order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function enableAffixesForDropArmors(): void
    {
        if (!Schema::hasTable('items') || !Schema::hasColumn('items', 'affix_enabled')) {
            return;
        }

        $query = DB::table('items')
            ->where('type', 'armor')
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('is_shop_item')
                    ->orWhere('is_shop_item', false);
            })
            ->whereNotIn('rarity', ['EPIC', 'epic', 'LEGEND', 'legend']);

        if (Schema::hasColumn('items', 'external_item_id')) {
            $query->where(function ($query) {
                $query->where('external_item_id', 'like', 'DROP_ARM_%')
                    ->orWhere(function ($query) {
                        $query->where('is_drop_enabled', true)
                            ->where('external_item_id', 'not like', 'SHOP_ARM_%')
                            ->where('external_item_id', 'not like', 'BR_%');
                    });
            });
        } elseif (Schema::hasColumn('items', 'is_drop_enabled')) {
            $query->where('is_drop_enabled', true);
        }

        $query->update(['affix_enabled' => true]);
    }
};
