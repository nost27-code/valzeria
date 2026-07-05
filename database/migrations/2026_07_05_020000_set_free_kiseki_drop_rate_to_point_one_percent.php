<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('game_settings')) {
            return;
        }

        $now = now();
        $exists = DB::table('game_settings')
            ->where('setting_key', 'kiseki.free_drop_rate_per_million')
            ->exists();

        if ($exists) {
            DB::table('game_settings')
                ->where('setting_key', 'kiseki.free_drop_rate_per_million')
                ->update([
                    'label' => '無償輝石ドロップ率',
                    'description' => '通常戦闘勝利時の抽選率。100万分率で指定。1000なら0.1%。',
                    'value' => '1000',
                    'value_type' => 'integer',
                    'updated_at' => $now,
                ]);
        } else {
            DB::table('game_settings')->insert([
                'setting_key' => 'kiseki.free_drop_rate_per_million',
                'label' => '無償輝石ドロップ率',
                'description' => '通常戦闘勝利時の抽選率。100万分率で指定。1000なら0.1%。',
                'value' => '1000',
                'value_type' => 'integer',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Cache::forget('game_settings.all');
    }

    public function down(): void
    {
        if (!Schema::hasTable('game_settings')) {
            return;
        }

        DB::table('game_settings')
            ->where('setting_key', 'kiseki.free_drop_rate_per_million')
            ->update([
                'description' => '通常戦闘勝利時の抽選率。100万分率で指定。300なら0.03%。',
                'value' => '300',
                'updated_at' => now(),
            ]);

        Cache::forget('game_settings.all');
    }
};
