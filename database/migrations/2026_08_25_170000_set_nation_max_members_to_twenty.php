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
            ->where('setting_key', 'nation.max_members')
            ->update([
                'value' => '20',
                'updated_at' => now(),
            ]);

        Cache::forget('game_settings.all');
    }

    public function down(): void
    {
        if (! Schema::hasTable('game_settings')) {
            return;
        }

        DB::table('game_settings')
            ->where('setting_key', 'nation.max_members')
            ->where('value', '20')
            ->update([
                'value' => '100',
                'updated_at' => now(),
            ]);

        Cache::forget('game_settings.all');
    }
};
