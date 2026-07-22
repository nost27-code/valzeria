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

        DB::table('game_settings')->updateOrInsert(
            ['setting_key' => 'exploration_maps.launch_bonus_enabled'],
            [
                'label' => '探索の地図実装記念ボーナス',
                'description' => 'ONの間、2026年7月29日 23:59まで探索の地図のドロップ率を3倍にします。途中停止するときはOFFにしてください。',
                'value' => '1',
                'value_type' => 'boolean',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        Cache::forget('game_settings.all');
    }

    public function down(): void
    {
        if (! Schema::hasTable('game_settings')) {
            return;
        }

        DB::table('game_settings')
            ->where('setting_key', 'exploration_maps.launch_bonus_enabled')
            ->delete();

        Cache::forget('game_settings.all');
    }
};
