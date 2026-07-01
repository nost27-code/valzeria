<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('game_settings')) {
            return;
        }

        $now = now();
        $legacyEnabled = DB::table('game_settings')
            ->where('setting_key', 'exploration.stamina_enabled')
            ->value('value');

        if (filter_var($legacyEnabled, FILTER_VALIDATE_BOOLEAN)) {
            DB::table('game_settings')->updateOrInsert(
                ['setting_key' => 'exploration.mode'],
                [
                    'label' => '探索方式',
                    'description' => '通常探索の開始方式です。cooldownで従来の連戦待機、staminaで探索力を消費します。',
                    'value' => 'stamina',
                    'value_type' => 'string',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        } else {
            DB::table('game_settings')
                ->where('setting_key', 'exploration.mode')
                ->update([
                    'label' => '探索方式',
                    'description' => '通常探索の開始方式です。cooldownで従来の連戦待機、staminaで探索力を消費します。',
                    'value_type' => 'string',
                    'updated_at' => $now,
                ]);
        }

        DB::table('game_settings')
            ->where('setting_key', 'exploration.stamina_enabled')
            ->delete();

        Cache::forget('game_settings.all');
    }

    public function down(): void
    {
        if (!Schema::hasTable('game_settings')) {
            return;
        }

        DB::table('game_settings')->updateOrInsert(
            ['setting_key' => 'exploration.stamina_enabled'],
            [
                'label' => '探索力制を有効化',
                'description' => '旧設定です。現在は exploration.mode を使用します。',
                'value' => '0',
                'value_type' => 'boolean',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('game_settings')
            ->where('setting_key', 'exploration.mode')
            ->update([
                'value' => 'cooldown',
                'updated_at' => now(),
            ]);

        Cache::forget('game_settings.all');
    }
};
