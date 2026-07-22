<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('game_settings')) {
            return;
        }

        DB::table('game_settings')
            ->where('setting_key', 'exploration_maps.launch_bonus_enabled')
            ->delete();

        Cache::forget('game_settings.all');
    }

    public function down(): void
    {
        if (! Schema::hasTable('game_settings')) {
            return;
        }

        DB::table('game_settings')->updateOrInsert(
            ['setting_key' => 'exploration_maps.launch_bonus_enabled'],
            [
                'label' => '探索の地図実装記念ボーナス',
                'description' => '廃止済みの設定です。',
                'value' => '0',
                'value_type' => 'boolean',
                'updated_at' => now(),
            ],
        );

        Cache::forget('game_settings.all');
    }
};
