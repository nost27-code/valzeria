<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nations', function (Blueprint $table): void {
            $table->json('decoration_settings')->nullable()->after('header_background_key');
        });

        Schema::create('nation_goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->string('title', 60);
            $table->string('metric_type', 32);
            $table->foreignId('material_id')->nullable()->constrained()->nullOnDelete();
            $table->string('facility_type', 32)->nullable();
            $table->unsignedBigInteger('target_value')->nullable();
            $table->string('description', 200)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('deadline_at')->nullable();
            $table->string('status', 24)->default('active');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->index(['nation_id', 'status', 'id'], 'nation_goals_status_id_idx');
            $table->index(['nation_id', 'status', 'deadline_at'], 'nation_goals_deadline_idx');
        });

        Schema::create('nation_wanted_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->foreignId('updated_by_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->string('purpose_note', 100)->nullable();
            $table->unsignedTinyInteger('display_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->dateTime('deactivated_at')->nullable();
            $table->timestamps();
            $table->unique(['nation_id', 'material_id'], 'nation_wanted_material_unique');
            $table->index(['nation_id', 'display_order'], 'nation_wanted_material_order_idx');
            $table->index(['nation_id', 'is_active'], 'nation_wanted_material_active_idx');
        });

        Schema::create('nation_achievements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->string('achievement_key', 64);
            $table->dateTime('unlocked_at');
            $table->json('metadata')->nullable();
            $table->unsignedTinyInteger('display_position')->nullable();
            $table->timestamps();
            $table->unique(['nation_id', 'achievement_key'], 'nation_achievement_unique');
            $table->index(['nation_id', 'display_position'], 'nation_achievement_showcase_idx');
        });

        Schema::create('nation_war_preparation_presets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('updated_by_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->string('name', 60);
            $table->unsignedBigInteger('pool_contribution_points')->default(0);
            $table->unsignedBigInteger('facility_upgrade_limit_points')->default(0);
            $table->json('facility_priority');
            $table->unsignedBigInteger('repair_reserve_warning_points')->default(0);
            $table->unsignedTinyInteger('display_order')->default(1);
            $table->timestamps();
            $table->unique(['nation_id', 'display_order'], 'nation_war_preset_order_unique');
        });

        if (Schema::hasTable('game_settings')) {
            $now = now();
            $existing = DB::table('game_settings')->where('setting_key', 'nation.max_members')->first();
            if (! $existing) {
                DB::table('game_settings')->insert([
                    'setting_key' => 'nation.max_members',
                    'label' => '国家 緊急定員上限',
                    'description' => '国家Lv由来の定員へ適用する全国家共通の緊急上限。',
                    'value' => '100',
                    'value_type' => 'integer',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } elseif ((string) $existing->value === '20') {
                DB::table('game_settings')->where('setting_key', 'nation.max_members')->update([
                    'label' => '国家 緊急定員上限',
                    'description' => '国家Lv由来の定員へ適用する全国家共通の緊急上限。',
                    'value' => '100',
                    'updated_at' => $now,
                ]);
            }
            Cache::forget('game_settings.all');
        }
    }

    public function down(): void
    {
        $hasData = DB::table('nation_goals')->exists()
            || DB::table('nation_wanted_materials')->exists()
            || DB::table('nation_achievements')->exists()
            || DB::table('nation_war_preparation_presets')->exists()
            || DB::table('nations')->whereNotNull('decoration_settings')->exists();
        if ($hasData) {
            throw new RuntimeException('国家Lv特典データが記録済みのためrollbackできません。機能を停止し、forward migrationで復旧してください。');
        }

        Schema::dropIfExists('nation_war_preparation_presets');
        Schema::dropIfExists('nation_achievements');
        Schema::dropIfExists('nation_wanted_materials');
        Schema::dropIfExists('nation_goals');
        Schema::table('nations', function (Blueprint $table): void {
            $table->dropColumn('decoration_settings');
        });

        if (Schema::hasTable('game_settings')) {
            DB::table('game_settings')
                ->where('setting_key', 'nation.max_members')
                ->where('value', '100')
                ->where('label', '国家 緊急定員上限')
                ->where('description', '国家Lv由来の定員へ適用する全国家共通の緊急上限。')
                ->update([
                    'label' => '国家 最大人数',
                    'description' => '1国家へ所属できる最大人数。',
                    'value' => '20',
                    'updated_at' => now(),
                ]);
            Cache::forget('game_settings.all');
        }
    }
};
