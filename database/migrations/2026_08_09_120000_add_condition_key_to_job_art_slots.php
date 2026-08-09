<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('character_job_art_slots')
            && ! Schema::hasColumn('character_job_art_slots', 'condition_key')
        ) {
            Schema::table('character_job_art_slots', function (Blueprint $table): void {
                $table->string('condition_key', 40)->default('always')->after('activation_policy');
            });
        }

        if (Schema::hasTable('job_art_preset_slots')
            && ! Schema::hasColumn('job_art_preset_slots', 'condition_key')
        ) {
            Schema::table('job_art_preset_slots', function (Blueprint $table): void {
                $table->string('condition_key', 40)->default('always')->after('activation_policy');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('job_art_preset_slots')
            && Schema::hasColumn('job_art_preset_slots', 'condition_key')
        ) {
            Schema::table('job_art_preset_slots', function (Blueprint $table): void {
                $table->dropColumn('condition_key');
            });
        }

        if (Schema::hasTable('character_job_art_slots')
            && Schema::hasColumn('character_job_art_slots', 'condition_key')
        ) {
            Schema::table('character_job_art_slots', function (Blueprint $table): void {
                $table->dropColumn('condition_key');
            });
        }
    }
};
