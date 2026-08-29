<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('character_job_art_context_settings')) {
            return;
        }

        $hadStrategyMode = Schema::hasColumn('character_job_art_context_settings', 'strategy_mode');
        $hadStrategySettings = Schema::hasColumn('character_job_art_context_settings', 'strategy_settings');

        if (! $hadStrategyMode) {
            Schema::table('character_job_art_context_settings', function (Blueprint $table): void {
                $table->string('strategy_mode', 20)
                    ->default('custom')
                    ->after('sp_policy');
            });
        } elseif (! $hadStrategySettings) {
            // Recover the interrupted pre-release draft that created only the
            // mode column. This shape is indistinguishable from an interrupted
            // post-release down(); public rollback is therefore prohibited and
            // re-up intentionally normalizes AUTO before restoring the JSON.
            DB::table('character_job_art_context_settings')
                ->where('strategy_mode', 'auto')
                ->update(['strategy_mode' => 'custom']);
        }

        if (! $hadStrategySettings) {
            Schema::table('character_job_art_context_settings', function (Blueprint $table): void {
                $table->json('strategy_settings')->nullable()->after('strategy_mode');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('character_job_art_context_settings')) {
            return;
        }

        if (Schema::hasColumn('character_job_art_context_settings', 'strategy_settings')) {
            // CAUTION: rolling this migration back after release discards every
            // saved detailed-strategy and SP-output selection.
            Schema::table('character_job_art_context_settings', function (Blueprint $table): void {
                $table->dropColumn('strategy_settings');
            });
        }

        if (Schema::hasColumn('character_job_art_context_settings', 'strategy_mode')) {
            Schema::table('character_job_art_context_settings', function (Blueprint $table): void {
                $table->dropColumn('strategy_mode');
            });
        }
    }
};
