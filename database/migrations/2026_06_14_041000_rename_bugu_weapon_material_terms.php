<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NAME_MAP = [
        '武具の結晶' => '武器の結晶',
        '武具の核' => '武器の核',
        '古代武具片' => '古代武器片',
        '伝説の武具紋章' => '伝説の武器紋章',
    ];

    private const MATERIAL_NAME_BY_CODE = [
        'WEV0002' => '武器の結晶',
        'WEV0003' => '武器の核',
        'WEV0004' => '古代武器片',
        'WEV0007' => '伝説の武器紋章',
    ];

    public function up(): void
    {
        $this->renameMaterialMasters();
        $this->renameWeaponRecipeIngredients();
        $this->renameWeaponEnhancementRecipeJson();
    }

    public function down(): void
    {
        // Name cleanup only; do not reintroduce the old "武具" wording.
    }

    private function renameMaterialMasters(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        foreach (self::MATERIAL_NAME_BY_CODE as $code => $name) {
            DB::table('materials')
                ->where('material_code', $code)
                ->update([
                    'name' => $name,
                    'updated_at' => now(),
                ]);
        }
    }

    private function renameWeaponRecipeIngredients(): void
    {
        if (!Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        foreach (self::MATERIAL_NAME_BY_CODE as $code => $name) {
            DB::table('weapon_evolution_recipe_ingredients')
                ->where('ingredient_id', $code)
                ->update([
                    'ingredient_name' => $name,
                    'updated_at' => now(),
                ]);
        }
    }

    private function renameWeaponEnhancementRecipeJson(): void
    {
        if (!Schema::hasTable('weapon_enhancement_recipes')) {
            return;
        }

        foreach (self::NAME_MAP as $from => $to) {
            DB::table('weapon_enhancement_recipes')
                ->where('materials', 'like', '%' . $from . '%')
                ->update([
                    'materials' => DB::raw("REPLACE(materials, " . DB::getPdo()->quote($from) . ', ' . DB::getPdo()->quote($to) . ')'),
                    'updated_at' => now(),
                ]);
        }
    }
};
