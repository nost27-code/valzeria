<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('character_job_art_context_settings')) {
            Schema::create('character_job_art_context_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->string('battle_context', 20);
                $table->string('sp_policy', 20)->default('aggressive');
                $table->timestamps();

                $table->unique(['character_id', 'battle_context'], 'character_job_art_context_setting_unique');
            });
        }

        if (Schema::hasTable('job_art_presets')
            && ! Schema::hasColumn('job_art_presets', 'sp_policy')
        ) {
            Schema::table('job_art_presets', function (Blueprint $table): void {
                $table->string('sp_policy', 20)->default('aggressive')->after('source_context');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('job_art_presets')
            && Schema::hasColumn('job_art_presets', 'sp_policy')
        ) {
            Schema::table('job_art_presets', function (Blueprint $table): void {
                $table->dropColumn('sp_policy');
            });
        }

        Schema::dropIfExists('character_job_art_context_settings');
    }
};
