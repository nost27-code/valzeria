<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enemies', function (Blueprint $table) {
            if (!Schema::hasColumn('enemies', 'enemy_level')) {
                $table->unsignedSmallInteger('enemy_level')->nullable()->after('area_id');
            }
            if (!Schema::hasColumn('enemies', 'family_key')) {
                $table->string('family_key', 50)->default('standard')->after('type_name');
            }
            if (!Schema::hasColumn('enemies', 'variant_key')) {
                $table->string('variant_key', 50)->default('none')->after('family_key');
            }
            if (!Schema::hasColumn('enemies', 'role_key')) {
                $table->string('role_key', 50)->default('normal')->after('variant_key');
            }
            if (!Schema::hasColumn('enemies', 'stat_generation_version')) {
                $table->string('stat_generation_version', 30)->nullable()->after('role_key');
            }
            if (!Schema::hasColumn('enemies', 'is_stat_locked')) {
                $table->boolean('is_stat_locked')->default(true)->after('stat_generation_version');
            }
            if (!Schema::hasColumn('enemies', 'generated_at')) {
                $table->timestamp('generated_at')->nullable()->after('is_stat_locked');
            }
            if (!Schema::hasColumn('enemies', 'manual_adjustment_note')) {
                $table->text('manual_adjustment_note')->nullable()->after('generated_at');
            }
        });

        Schema::table('areas', function (Blueprint $table) {
            if (!Schema::hasColumn('areas', 'layer_key')) {
                $table->string('layer_key', 50)->default('surface')->after('recommended_level_max');
            }
            if (!Schema::hasColumn('areas', 'is_recommended_level_locked')) {
                $table->boolean('is_recommended_level_locked')->default(false)->after('layer_key');
            }
            if (!Schema::hasColumn('areas', 'level_generation_version')) {
                $table->string('level_generation_version', 30)->nullable()->after('is_recommended_level_locked');
            }
        });

        if (Schema::hasColumn('enemies', 'family_key')) {
            $guesser = new \App\Services\Enemy\EnemyStatMetadataGuesser();
            DB::table('enemies')
                ->orderBy('id')
                ->select('id', 'name', 'level', 'is_boss', 'role', 'type_name', 'element', 'action_pattern')
                ->chunk(200, function ($enemies) use ($guesser): void {
                    foreach ($enemies as $enemy) {
                        $metadata = $guesser->guess((array) $enemy);
                        DB::table('enemies')
                            ->where('id', $enemy->id)
                            ->update([
                                'enemy_level' => (int) ($enemy->level ?? 1),
                                'family_key' => $metadata['family_key'],
                                'variant_key' => $metadata['variant_key'],
                                'role_key' => $metadata['role_key'],
                                'stat_generation_version' => config('enemy_stat_generation.version'),
                                'is_stat_locked' => true,
                                'manual_adjustment_note' => $metadata['manual_adjustment_note'],
                            ]);
                    }
                });
        }

        if (Schema::hasColumn('areas', 'layer_key')) {
            DB::table('areas')
                ->whereNull('layer_key')
                ->orWhere('layer_key', '')
                ->update([
                    'layer_key' => 'surface',
                    'level_generation_version' => config('enemy_stat_generation.version'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('enemies', function (Blueprint $table) {
            $columns = [
                'enemy_level',
                'family_key',
                'variant_key',
                'role_key',
                'stat_generation_version',
                'is_stat_locked',
                'generated_at',
                'manual_adjustment_note',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('enemies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('areas', function (Blueprint $table) {
            foreach (['layer_key', 'is_recommended_level_locked', 'level_generation_version'] as $column) {
                if (Schema::hasColumn('areas', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
