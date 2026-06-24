<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const HIGH_RANKS = ['A', 'S', 'SS', 'SSS'];

    public function up(): void
    {
        $this->disableLegacyWeaponRecipes();
        $this->disableLegacyArmorRecipes();
        $this->disableLegacyAccessoryRecipes();
    }

    public function down(): void
    {
        // Master-data cleanup only. Legacy high-rank linear evolution recipes are not restored.
    }

    private function disableLegacyWeaponRecipes(): void
    {
        if (!Schema::hasTable('weapon_evolution_recipes')) {
            return;
        }

        DB::table('weapon_evolution_recipes')
            ->whereIn('from_rank', self::HIGH_RANKS)
            ->where('is_active', true)
            ->where('recipe_id', 'not like', 'BR_%')
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }

    private function disableLegacyArmorRecipes(): void
    {
        if (!Schema::hasTable('armor_evolution_recipes')) {
            return;
        }

        DB::table('armor_evolution_recipes')
            ->whereIn('from_rank', self::HIGH_RANKS)
            ->where('is_active', true)
            ->where('evolution_recipe_id', 'not like', 'BR_%')
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }

    private function disableLegacyAccessoryRecipes(): void
    {
        if (!Schema::hasTable('accessory_evolution_recipes')) {
            return;
        }

        DB::table('accessory_evolution_recipes')
            ->whereIn('from_rank', self::HIGH_RANKS)
            ->where('is_active', true)
            ->where('recipe_id', 'not like', 'BR_%')
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }
};
