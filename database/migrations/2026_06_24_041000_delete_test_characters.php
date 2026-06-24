<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // v2: arena_logs は attacker_id/defender_id で参照するため修正済み
        $names = ['TestAdventurer', 'てｓｔ', 'testplayer', 'TestHero'];
        $ids = DB::table('characters')->whereIn('name', $names)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // 関連データを外部キー順に削除
        $tables = [
            'character_area_progress',
            'character_exploration_states',
            'character_notifications',
            'character_items',
            'character_materials',
            'character_consumable_items',
            'character_skills',
            'character_job_experiences',
            'arena_rankings',
            'champ_states',
            'valmon_states',
            'contact_messages',
            'private_messages',
        ];

        foreach ($tables as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                DB::table($table)->whereIn('character_id', $ids)->delete();
            }
        }

        // arena_logs は attacker_id / defender_id で参照
        if (\Illuminate\Support\Facades\Schema::hasTable('arena_logs')) {
            DB::table('arena_logs')->whereIn('attacker_id', $ids)->orWhereIn('defender_id', $ids)->delete();
        }

        DB::table('characters')->whereIn('id', $ids)->delete();
    }

    public function down(): void
    {
        // 削除済みのためロールバック不可
    }
};
