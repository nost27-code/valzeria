<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceInTable('materials', [
            'name',
            'category',
            'main_use',
            'material_type',
            'category_id',
            'obtain_method',
        ]);

        $this->replaceInTable('weapon_evolution_recipe_ingredients', [
            'ingredient_name',
        ]);

        $this->replaceInTable('weapon_enhancement_recipes', [
            'materials',
        ]);

        $this->replaceInTable('weapon_evolution_recipes', [
            'recipe_name',
            'required_material_name_1',
            'required_material_name_2',
            'required_material_name_3',
            'note',
        ]);
    }

    public function down(): void
    {
        // Wording cleanup only; do not reintroduce the old term.
    }

    private function replaceInTable(string $table, array $columns): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                continue;
            }

            $updates = [
                $column => DB::raw("REPLACE({$column}, " . DB::getPdo()->quote('武具') . ', ' . DB::getPdo()->quote('武器') . ')'),
            ];

            if (Schema::hasColumn($table, 'updated_at')) {
                $updates['updated_at'] = now();
            }

            DB::table($table)
                ->where($column, 'like', '%武具%')
                ->update($updates);
        }
    }
};
