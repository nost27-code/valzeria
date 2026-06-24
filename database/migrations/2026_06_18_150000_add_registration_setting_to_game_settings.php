<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('game_settings')) {
            return;
        }

        DB::table('game_settings')->updateOrInsert(
            ['setting_key' => 'auth.registration_open'],
            [
                'label' => '新規登録受付',
                'description' => '1で新規登録を受付、0でメール新規登録・Google初回作成・ゲスト開始を停止します。既存アカウントのログインは許可されます。',
                'value' => '0',
                'value_type' => 'boolean',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('game_settings')) {
            return;
        }

        DB::table('game_settings')
            ->where('setting_key', 'auth.registration_open')
            ->delete();
    }
};
