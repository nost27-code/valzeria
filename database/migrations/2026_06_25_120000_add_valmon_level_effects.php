<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('character_exploration_states')) {
            Schema::table('character_exploration_states', function (Blueprint $table) {
                if (!Schema::hasColumn('character_exploration_states', 'valmon_heal_used')) {
                    $table->boolean('valmon_heal_used')->default(false)->after('valmon_material_found');
                }
            });
        }

        if (Schema::hasTable('titles')) {
            DB::table('titles')->updateOrInsert(
                ['name' => '名相棒'],
                [
                    'category' => 'ヴァルモン',
                    'rarity' => 'rare',
                    'description' => 'Lv100まで育った相棒ヴァルモンと深い絆を結んだ証。',
                    'hint' => '相棒ヴァルモンをLv100まで育てる',
                    'unlock_type' => 'valmon_level',
                    'target_type' => 'valmon_level',
                    'target_id' => '100',
                    'source_master' => 'valmon',
                    'display_order' => 950,
                    'is_hidden' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('character_exploration_states') && Schema::hasColumn('character_exploration_states', 'valmon_heal_used')) {
            Schema::table('character_exploration_states', function (Blueprint $table) {
                $table->dropColumn('valmon_heal_used');
            });
        }

        if (Schema::hasTable('titles')) {
            DB::table('titles')
                ->where('unlock_type', 'valmon_level')
                ->where('target_type', 'valmon_level')
                ->where('target_id', '100')
                ->delete();
        }
    }
};
