<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->copyBossSlots();
        $this->copyBossSettings();
    }

    public function down(): void
    {
        // raid rows become player-owned settings after release. Deleting them on
        // rollback would destroy post-release edits, so code rollback must leave
        // this harmless context data in place.
    }

    private function copyBossSlots(): void
    {
        if (! Schema::hasTable('character_job_art_slots')
            || ! Schema::hasColumn('character_job_art_slots', 'battle_context')) {
            return;
        }

        $columns = ['character_id', 'battle_context', 'slot_no', 'skill_id'];
        $selects = [
            'boss_slots.character_id',
            DB::raw("'raid'"),
            'boss_slots.slot_no',
            'boss_slots.skill_id',
        ];

        foreach (['activation_policy', 'condition_key', 'created_at', 'updated_at'] as $column) {
            if (Schema::hasColumn('character_job_art_slots', $column)) {
                $columns[] = $column;
                $selects[] = 'boss_slots.'.$column;
            }
        }

        $source = DB::table('character_job_art_slots as boss_slots')
            ->select($selects)
            ->where('boss_slots.battle_context', 'boss')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('character_job_art_slots as raid_slots')
                    ->whereColumn('raid_slots.character_id', 'boss_slots.character_id')
                    ->where('raid_slots.battle_context', 'raid');
            });

        DB::table('character_job_art_slots')->insertUsing($columns, $source);
    }

    private function copyBossSettings(): void
    {
        if (! Schema::hasTable('character_job_art_context_settings')
            || ! Schema::hasColumn('character_job_art_context_settings', 'battle_context')) {
            return;
        }

        $columns = ['character_id', 'battle_context'];
        $selects = [
            'boss_settings.character_id',
            DB::raw("'raid'"),
        ];

        foreach (['sp_policy', 'strategy_mode', 'strategy_settings', 'created_at', 'updated_at'] as $column) {
            if (Schema::hasColumn('character_job_art_context_settings', $column)) {
                $columns[] = $column;
                $selects[] = 'boss_settings.'.$column;
            }
        }

        $source = DB::table('character_job_art_context_settings as boss_settings')
            ->select($selects)
            ->where('boss_settings.battle_context', 'boss')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('character_job_art_context_settings as raid_settings')
                    ->whereColumn('raid_settings.character_id', 'boss_settings.character_id')
                    ->where('raid_settings.battle_context', 'raid');
            });

        DB::table('character_job_art_context_settings')->insertUsing($columns, $source);
    }
};
