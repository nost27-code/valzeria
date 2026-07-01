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

        DB::table('game_settings')
            ->where('setting_key', 'exploration.stamina_value_multiplier')
            ->delete();

        Cache::forget('game_settings.all');
    }

    public function down(): void
    {
        if (!Schema::hasTable('game_settings')) {
            return;
        }

        DB::table('game_settings')->updateOrInsert(
            ['setting_key' => 'exploration.stamina_value_multiplier'],
            [
                'label' => '探索力 報酬倍率',
                'description' => 'スタミナ制の通常探索1回で得るEXP/Gold/職業EXPの倍率です。',
                'value' => '2',
                'value_type' => 'integer',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        Cache::forget('game_settings.all');
    }
};
