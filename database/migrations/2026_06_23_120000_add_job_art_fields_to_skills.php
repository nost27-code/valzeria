<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropNameUniqueIndexIfExists();

        Schema::table('skills', function (Blueprint $table) {
            if (!Schema::hasColumn('skills', 'skill_type')) {
                $table->string('skill_type', 50)->default('special')->after('job_id');
            }
            if (!Schema::hasColumn('skills', 'learn_rank')) {
                $table->unsignedTinyInteger('learn_rank')->nullable()->after('skill_type');
            }
            if (!Schema::hasColumn('skills', 'art_cost')) {
                $table->unsignedTinyInteger('art_cost')->default(0)->after('learn_rank');
            }
            if (!Schema::hasColumn('skills', 'art_category')) {
                $table->string('art_category', 50)->nullable()->after('art_cost');
            }
            if (!Schema::hasColumn('skills', 'limit_group')) {
                $table->string('limit_group', 50)->default('NONE')->after('art_category');
            }
            if (!Schema::hasColumn('skills', 'effect_template')) {
                $table->string('effect_template', 80)->nullable()->after('damage_type');
            }
            if (!Schema::hasColumn('skills', 'element')) {
                $table->string('element', 50)->nullable()->after('effect_template');
            }
            if (!Schema::hasColumn('skills', 'power')) {
                $table->unsignedSmallInteger('power')->default(0)->after('element');
            }
            if (!Schema::hasColumn('skills', 'duration_turns')) {
                $table->unsignedTinyInteger('duration_turns')->nullable()->after('power');
            }
            if (!Schema::hasColumn('skills', 'cooldown_turns')) {
                $table->unsignedTinyInteger('cooldown_turns')->default(0)->after('duration_turns');
            }
            if (!Schema::hasColumn('skills', 'max_uses_per_battle')) {
                $table->unsignedTinyInteger('max_uses_per_battle')->nullable()->after('cooldown_turns');
            }
            if (!Schema::hasColumn('skills', 'inherit_on_master')) {
                $table->boolean('inherit_on_master')->default(true)->after('max_uses_per_battle');
            }
            if (!Schema::hasColumn('skills', 'inherit_policy')) {
                $table->string('inherit_policy', 50)->default('reduced')->after('inherit_on_master');
            }
            if (!Schema::hasColumn('skills', 'inherited_rate')) {
                $table->decimal('inherited_rate', 4, 2)->default(1.00)->after('inherit_policy');
            }
            if (!Schema::hasColumn('skills', 'pve_enabled')) {
                $table->boolean('pve_enabled')->default(true)->after('inherited_rate');
            }
            if (!Schema::hasColumn('skills', 'boss_enabled')) {
                $table->boolean('boss_enabled')->default(true)->after('pve_enabled');
            }
            if (!Schema::hasColumn('skills', 'champ_enabled')) {
                $table->boolean('champ_enabled')->default(true)->after('boss_enabled');
            }
            if (!Schema::hasColumn('skills', 'reward_scope')) {
                $table->string('reward_scope', 50)->default('none')->after('champ_enabled');
            }
            if (!Schema::hasColumn('skills', 'sort_order')) {
                $table->unsignedSmallInteger('sort_order')->default(0)->after('reward_scope');
            }
            if (!Schema::hasColumn('skills', 'memo')) {
                $table->text('memo')->nullable()->after('description');
            }
        });

        DB::table('skills')
            ->whereNull('skill_type')
            ->orWhere('skill_type', '')
            ->update(['skill_type' => 'special']);
    }

    public function down(): void
    {
        // 既存skillsテーブルを拡張するmigrationのため、破壊的なrollbackは行わない。
    }

    private function dropNameUniqueIndexIfExists(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS skills_name_unique');
            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $exists = DB::selectOne("SHOW INDEX FROM skills WHERE Key_name = 'skills_name_unique'");
            if ($exists) {
                DB::statement('ALTER TABLE skills DROP INDEX skills_name_unique');
            }
        }
    }
};
