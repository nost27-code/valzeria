<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('character_sub_area_exploration_states')) {
            return;
        }

        $addIsActive = ! Schema::hasColumn('character_sub_area_exploration_states', 'is_active');
        $addSelectedExploreCount = ! Schema::hasColumn('character_sub_area_exploration_states', 'selected_explore_count');
        if (! $addIsActive && ! $addSelectedExploreCount) {
            return;
        }

        Schema::table('character_sub_area_exploration_states', function (Blueprint $table) use ($addIsActive, $addSelectedExploreCount) {
            if ($addIsActive) {
                $table->boolean('is_active')->default(false);
            }
            if ($addSelectedExploreCount) {
                $table->unsignedSmallInteger('selected_explore_count')->default(1);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('character_sub_area_exploration_states')) {
            return;
        }

        $columns = array_values(array_filter(
            ['is_active', 'selected_explore_count'],
            fn (string $column) => Schema::hasColumn('character_sub_area_exploration_states', $column)
        ));
        if ($columns === []) {
            return;
        }

        Schema::table('character_sub_area_exploration_states', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
